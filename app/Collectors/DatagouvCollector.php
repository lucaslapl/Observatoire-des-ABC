<?php

namespace App\Collectors;

use App\Models\Projet;
use App\Services\CommuneCorrectionsService;
use App\Services\CsvReaderService;
use App\Services\DownloadService;
use App\Services\ProjectIdService;
use App\Services\ProjetRepository;
use App\Services\RegionService;
use App\Services\StatusService;

/**
 * Registre principal OFB (data.gouv) : 1 ligne = 1 couple (projet x commune).
 */
class DatagouvCollector
{
    private const SNAPSHOT_DATE = null; // date dynamique au runtime

    public function __construct(
        private DownloadService $download,
        private CsvReaderService $csv,
        private ProjetRepository $projects,
        private ProjectIdService $ids,
        private CommuneCorrectionsService $corrections,
        private RegionService $regions,
        private StatusService $status,
    ) {}

    public function collect(): array
    {
        $snapshotDate = date('Y-m-d');
        $file = $this->download->downloadToCache(
            config('abc.sources.datagouv'),
            'registre ABC (data.gouv)',
        );
        $rows = $this->csv->read($file, ';');

        $grouped = [];
        foreach ($rows as $r) {
            $key = $this->ids->projetId(
                $r['nom'] ?? '',
                $r['structure_porteuse'] ?? null,
                $this->toInt($r['annee_debut'] ?? null),
            );
            $grouped[$key][] = $r;
        }

        $projects = 0;
        foreach ($grouped as $key => $rs) {
            $first = $rs[0];
            $annee = $this->toInt($first['annee_debut'] ?? null);
            $communes = [];
            foreach ($rs as $r) {
                $c = $this->corrections->corrigerCommune([
                    'code_geographique' => $r['code_geographique'] ?? '',
                    'libelle_geographique' => $r['libelle_geographique'] ?? null,
                    'epci' => $r['epci'] ?? null,
                    'libelle_epci' => $r['libelle_epci'] ?? null,
                    'departement' => $r['departement'] ?? null,
                    'libelle_departement' => $r['libelle_departement'] ?? null,
                    'region' => $this->regions->label($r['region'] ?? null),
                    'libelle_pnr' => $r['libelle_pnr'] ?? null,
                ]);
                if ($c['code_geographique']) {
                    $communes[] = $c;
                }
            }

            $this->projects->upsertProjet([
                'id' => $key,
                'nom' => $first['nom'] ?? '',
                'structure_porteuse' => $first['structure_porteuse'] ?: null,
                'type_de_structure_porteuse' => $first['type_de_structure_porteuse'] ?: null,
                'annee_debut' => $annee,
                'avancement_raw' => $first['avancement'] ?? null,
                'statut' => $this->status->avancementToStatut($first['avancement'] ?? null),
                'source' => 'data.gouv',
                'communes' => $communes,
            ]);

            if (($first['avancement'] ?? null) !== null && $first['avancement'] !== '') {
                $this->projects->recordSnapshot($snapshotDate, $key, $first['avancement'], 'data.gouv');
            }
            $projects++;
        }

        return [$projects, count($rows)];
    }

    public function toInt(?string $v): ?int
    {
        // Équivalent de Number.parseInt(v, 10) côté Node.
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;

        return is_numeric($v) ? $n : (preg_match('/^\s*-?\d+/', $v) ? $n : null);
    }
}
