<?php

namespace App\Collectors;

use App\Services\CommuneCorrectionsService;
use App\Services\CsvReaderService;
use App\Services\DownloadService;
use App\Services\ProjectIdService;
use App\Services\ProjetRepository;

/**
 * Projets ABC financés via le lot "Biodiversité" (P113) du Fonds vert →
 * projets récents (à venir).
 */
class FondsVertCollector
{
    public function __construct(
        private DownloadService $download,
        private CsvReaderService $csv,
        private ProjetRepository $projects,
        private ProjectIdService $ids,
        private CommuneCorrectionsService $corrections,
    ) {}

    public function collect(): array
    {
        $specs = [
            ['url' => config('abc.sources.fondsvert2024'), 'year' => 2024, 'rich' => false],
            ['url' => config('abc.sources.fondsvert2025'), 'year' => 2025, 'rich' => true],
        ];

        $byYear = [];
        foreach ($specs as $s) {
            $file = $this->download->downloadToCache($s['url'], "Fonds vert biodiversité {$s['year']}");
            $rows = $this->csv->read($file, ',');
            $hits = array_filter($rows, fn ($r) => $this->isAbc($r));
            $byYear[$s['year']] = count($hits);

            foreach ($hits as $r) {
                $nom = trim($r['nom_du_projet'] ?? '');
                if ($nom === '') {
                    continue;
                }
                $benef = trim($r['raison_sociale_beneficiaire'] ?? $r['nom_fournisseur'] ?? '');
                $id = $this->ids->projetId($nom, $benef !== '' ? $benef : null, $s['year']);

                $communes = [];
                if ($s['rich'] && ! empty($r['code_commune'])) {
                    $communes = [$this->corrections->corrigerCommune([
                        'code_geographique' => $r['code_commune'],
                        'libelle_geographique' => $r['nom_commune'] ?? null,
                        'libelle_departement' => $r['nom_departement'] ?? null,
                        'region' => $r['nom_region'] ?? null,
                    ])];
                }

                $this->projects->upsertProjet([
                    'id' => $id,
                    'nom' => $nom,
                    'structure_porteuse' => $benef !== '' ? $benef : null,
                    'type_de_structure_porteuse' => null,
                    'annee_debut' => null,
                    'avancement_raw' => "Fonds vert {$s['year']}",
                    'statut' => 'a_venir',
                    'ami_ofb' => true,
                    'source' => "fondsvert-p113-{$s['year']}",
                    'url_page' => null,
                    'communes' => $communes,
                ]);
            }
        }

        return ['total' => array_sum($byYear), 'by_year' => $byYear];
    }

    private function isAbc(array $r): bool
    {
        $hay = ($r['nom_du_projet'] ?? '').' '.($r['resume_du_projet'] ?? '').' '.($r['demarche'] ?? '');
        if (! $hay) {
            return false;
        }
        if (preg_match('/abc\s*terre/i', $hay)) {
            return false; // "ABC Terre" : carbone des sols, à exclure
        }

        return preg_match('/atlas de la biodiversit[ée] communale/i', $hay) === 1
            || preg_match('/\bABC\b/i', $hay) === 1;
    }
}
