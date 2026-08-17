<?php

namespace App\Collectors;

use App\Models\Projet;
use App\Services\CommuneCorrectionsService;
use App\Services\CsvReaderService;
use App\Services\DownloadService;
use App\Services\ProjectIdService;
use App\Services\ProjetRepository;
use App\Services\StatusService;

/**
 * Export archivé du site abc.naturefrance.fr (point dans le temps, 1 ligne =
 * commune). Instantané 2022 — non destructif : n'ajoute que les snapshots et
 * les projets absents du registre data.gouv.
 */
class WaybackCollector
{
    private const SNAPSHOT_DATE = '2022-12-06';

    public function __construct(
        private DownloadService $download,
        private CsvReaderService $csv,
        private ProjetRepository $projects,
        private ProjectIdService $ids,
        private CommuneCorrectionsService $corrections,
        private StatusService $status,
    ) {}

    public function collect(): array
    {
        $file = $this->download->downloadToCache(
            config('abc.sources.wayback'),
            'historique ABC (Wayback 2022)',
        );
        $rows = $this->csv->read($file, ',');

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

            // Le registre data.gouv (plus récent) fait foi : on n'écrase jamais ses
            // statuts. L'instantané 2022 sert uniquement d'historique (snapshot) et
            // d'apport pour les projets disparus du registre.
            if (! Projet::whereKey($key)->exists()) {
                $communes = [];
                foreach ($rs as $r) {
                    $c = $this->corrections->corrigerCommune([
                        'code_geographique' => $r['code_commune'] ?? '',
                        'libelle_geographique' => $r['commune'] ?? null,
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
                    'annee_debut' => $this->toInt($first['annee_debut'] ?? null),
                    'avancement_raw' => $first['avancement'] ?? null,
                    'statut' => $this->status->avancementToStatut($first['avancement'] ?? null),
                    'ami_ofb' => $this->parseAmi($first['ami_ofb'] ?? null),
                    'source' => 'wayback',
                    'url_page' => $first['ressource_documentaire'] ?: null,
                    'communes' => $communes,
                ]);
                $projects++;
            }

            if (($first['avancement'] ?? null) !== null && $first['avancement'] !== '') {
                $this->projects->recordSnapshot(self::SNAPSHOT_DATE, $key, $first['avancement'], 'wayback');
            }
        }

        return [$projects, count($rows)];
    }

    private function toInt(?string $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;

        return is_numeric($v) ? $n : (preg_match('/^\s*-?\d+/', $v) ? $n : null);
    }

    private function parseAmi(?string $v): ?bool
    {
        if (! $v) {
            return null;
        }

        return strtolower(trim($v)) === 'vrai' || trim($v) === '1';
    }
}
