<?php

namespace App\Services;

use App\Collectors\DatagouvCollector;
use App\Collectors\FondsVertCollector;
use App\Collectors\WaybackCollector;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use RuntimeException;

/**
 * Orchestration du collect complet (port de src/collect.ts).
 *
 * Stratégie de synchronisation (v2.1) : les sources sont ré-ingérées par
 * upsert, et chaque projet vu dans une source est enregistré
 * (ProjetRepository::markIngested). Ne sont supprimés que les projets
 * ABSENTS des sources ET sans travail humain. Ne jamais se fier à
 * updated_at : un upsert sans changement de données ne le rafraîchit pas.
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

    public function collectAll(bool $init = false, bool $force = false): array
    {
        ProjetRepository::refreshExclusions();
        ProjetRepository::resetIngested();

        $datagouv = $this->datagouv->collect();
        $wayback = $this->wayback->collect();
        $fondsVert = $this->fondsVert->collect();

        $stale = $this->deleteStaleProjects(ProjetRepository::ingestedIds(), $force);

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
     * Supprime les projets absents des sources (ids non ingérés), SAUF ceux
     * qui portent du travail humain (verifications, contributions) : le
     * delete en cascade emporterait ce travail. Les communes et snapshots
     * des projets supprimés partent en cascade.
     *
     * Garde-fous :
     * - ids ingérés vides → erreur (sources indisponibles) ;
     * - plus de la moitié de la base concernée → erreur, sauf $force :
     *   une chute massive d'une source ne doit jamais vider la base.
     *
     * @param  array<int, string>  $ingestedIds
     * @return int nombre de projets supprimés
     */
    public function deleteStaleProjects(array $ingestedIds, bool $force = false): int
    {
        if (empty($ingestedIds)) {
            throw new RuntimeException(
                'Aucun projet ingéré depuis les sources : suppression annulée (source indisponible ?).'
            );
        }

        $candidates = Projet::query()
            ->whereNotIn('id', $ingestedIds)
            ->whereNotIn('id', Verification::select('projet_id'))
            ->whereNotIn('id', Contribution::select('projet_id'))
            ->pluck('id');

        if ($candidates->isEmpty()) {
            return 0;
        }

        $existing = Projet::count();
        if (! $force && $candidates->count() > $existing / 2) {
            throw new RuntimeException(sprintf(
                'Suppression annulée : %d projets sur %d seraient supprimés (une source a-t-elle échoué ?).'.
                ' Relancez avec --force si cette chute est réellement voulue.',
                $candidates->count(),
                $existing,
            ));
        }

        Projet::whereIn('id', $candidates)->delete();

        return $candidates->count();
    }
}
