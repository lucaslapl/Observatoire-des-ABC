<?php

namespace App\Services;

use App\Collectors\DatagouvCollector;
use App\Collectors\FondsVertCollector;
use App\Collectors\WaybackCollector;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use Carbon\CarbonInterface;

/**
 * Orchestration du collect complet (port de src/collect.ts).
 *
 * Stratégie de synchronisation (v2) : les sources sont ré-ingérées par upsert,
 * puis les projets disparus des sources SANS travail humain sont supprimés
 * ciblé. Les tables verifications / contributions / audit_log ne sont jamais
 * purgées, et aucun superuser Postgres n'est requis (l'ancienne purge via
 * `session_replication_role = replica` a été supprimée).
 */
class CollectService
{
    public function __construct(
        private DatagouvCollector $datagouv,
        private WaybackCollector $wayback,
        private FondsVertCollector $fondsVert,
        private StatusRecomputeService $recompute,
        private GeocodeService $geocode,
        private AnomalyService $anomalies,
    ) {}

    public function collectAll(bool $init = false): array
    {
        ProjetRepository::refreshExclusions();

        // Horodatage du début du collect : tout projet upserté pendant le
        // collect aura updated_at >= $marker. Les autres sont des candidats
        // à la suppression (disparus des sources).
        $marker = now();

        $datagouv = $this->datagouv->collect();
        $wayback = $this->wayback->collect();
        $fondsVert = $this->fondsVert->collect();

        $stale = $this->deleteStaleProjects($marker);

        $this->recompute->recomputeStatuses();
        $geocoding = $this->geocode->enrichGeocoding();
        $anomalies = $this->anomalies->computeAnomalies();

        $total = Projet::count();
        $communes = Commune::count();
        $geocoded = Commune::whereNotNull('lon')->whereNotNull('lat')
            ->where(fn ($q) => $q->where('lon', '!=', 0)->orWhere('lat', '!=', 0))
            ->count();
        $statuses = Projet::selectRaw('statut, COUNT(*) AS n')
            ->groupBy('statut')
            ->orderByDesc('n')
            ->get()
            ->pluck('n', 'statut')
            ->all();

        return [
            'total' => $total,
            'communes' => $communes,
            'geocoded' => $geocoded,
            'stale_deleted' => $stale,
            'anomalies' => $anomalies,
            'statuses' => $statuses,
            'geocoding' => $geocoding,
            'sources' => [
                'datagouv' => ['projets' => $datagouv[0], 'communes' => $datagouv[1]],
                'wayback' => ['projets' => $wayback[0], 'communes' => $wayback[1]],
                'fondsvert' => $fondsVert,
            ],
        ];
    }

    /**
     * Supprime les projets non ré-ingérés depuis $marker (disparus des
     * sources), SAUF ceux qui portent du travail humain (verifications,
     * contributions) : le delete en cascade emporterait ce travail. Les
     * communes et snapshots des projets supprimés partent en cascade.
     *
     * @return int nombre de projets supprimés
     */
    public function deleteStaleProjects(CarbonInterface $marker): int
    {
        $stale = Projet::query()
            ->where('updated_at', '<', $marker)
            ->whereNotIn('id', Verification::select('projet_id'))
            ->whereNotIn('id', Contribution::select('projet_id'))
            ->pluck('id');

        if ($stale->isEmpty()) {
            return 0;
        }

        // Le cascade ne touchera que communes et snapshots : les lignes
        // humaines ont été exclues par les whereNotIn ci-dessus.
        Projet::whereIn('id', $stale)->delete();

        return $stale->count();
    }
}
