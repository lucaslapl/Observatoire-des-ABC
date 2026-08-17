<?php

namespace App\Services;

use App\Collectors\DatagouvCollector;
use App\Collectors\FondsVertCollector;
use App\Collectors\WaybackCollector;
use App\Models\Commune;
use App\Models\Projet;
use App\Models\Snapshot;
use Illuminate\Support\Facades\DB;

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
        ProjetRepository::refreshExclusions();
        $this->purgeReplicableData();

        $datagouv = $this->datagouv->collect();
        $wayback = $this->wayback->collect();
        $fondsVert = $this->fondsVert->collect();
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
     * Purge les tables re-collectables en désactivant temporairement les
     * contraintes de clé étrangère (équivalent de `PRAGMA foreign_keys = OFF`
     * du collect Node). Sans cela, le DELETE sur `projets` déclencherait les
     * CASCADE Postgres et effacerait verifications/contributions (travail humain).
     *
     * Postgres : `session_replication_role = replica` exige un rôle superuser.
     */
    public function purgeReplicableData(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('SET session_replication_role = replica;');
        } else {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }

        try {
            Snapshot::query()->delete();
            Commune::query()->delete();
            Projet::query()->delete();
        } finally {
            if ($driver === 'pgsql') {
                DB::statement('SET session_replication_role = DEFAULT;');
            } else {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
        }
    }
}
