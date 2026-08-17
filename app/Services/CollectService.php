<?php

namespace App\Services;

use App\Collectors\DatagouvCollector;
use App\Collectors\FondsVertCollector;
use App\Collectors\WaybackCollector;
use App\Models\Commune;
use App\Models\Projet;
use App\Models\Snapshot;

/**
 * Orchestration du collect complet (port de src/collect.ts).
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

    public function collectAll(): array
    {
        // Purge des données re-collectables (les tables de travail humain —
        // verifications, contributions, audit_log — ne sont PAS touchées).
        Snapshot::query()->delete();
        Commune::query()->delete();
        Projet::query()->delete();

        $this->datagouv->collect();
        $this->wayback->collect();
        $this->fondsVert->collect();
        $this->recompute->recomputeStatuses();
        $this->geocode->enrichGeocoding();
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

        echo "\n=== {$total} projets / {$communes} lignes communes / {$geocoded} géo ===\n";
        foreach ($statuses as $statut => $n) {
            echo '  '.str_pad((new StatusService)->statutLabel($statut), 16)." {$n}\n";
        }
        echo "anomalies détectées : {$anomalies} commune(s)\n";

        return [
            'total' => $total,
            'communes' => $communes,
            'geocoded' => $geocoded,
            'anomalies' => $anomalies,
            'statuses' => $statuses,
        ];
    }
}
