<?php

namespace App\Collectors;

use App\Models\AuditLog;
use App\Models\Financement;
use App\Models\Projet;
use App\Models\ProjectAlias;
use App\Models\Verification;
use App\Services\CsvReaderService;
use App\Services\DataGouvResolver;
use App\Services\DownloadService;
use App\Services\ProjectIdService;
use App\Services\ProjetRepository;
use Illuminate\Support\Collection;

/**
 * Financements « Fonds vert » (lot Biodiversité, P113).
 *
 * Depuis la phase 2 de l'audit du 2026-10-21, une ligne Fonds vert est un
 * FINANCEMENT accordé à un ABC existant, pas un ABC distinct : ce collector
 * ne crée JAMAIS de projet. Chaque ligne candidate ABC est rattachée :
 *
 * 1. à un projet existant par clé d'identité exacte (l'id qu'aurait créé
 *    l'ancien collector) ;
 * 2. sinon à un projet canonique dont un doublon de cette clé a été fusionné
 *    (project_aliases) ;
 * 3. sinon à un unique projet portant le même nom normalisé.
 *
 * Si la correspondance est ambiguë (plusieurs projets au même nom :
 * éditions distinctes ?) ou nouvelle (aucun projet), la ligne part en
 * worklist de vérification — aucune création silencieuse.
 */
class FondsVertCollector
{
    public function __construct(
        private DownloadService $download,
        private CsvReaderService $csv,
        private ProjetRepository $projects,
        private ProjectIdService $ids,
        private DataGouvResolver $resolver,
    ) {}

    public function collect(): array
    {
        $specs = [
            ['key' => 'fondsvert2024', 'year' => 2024],
            ['key' => 'fondsvert2025', 'year' => 2025],
        ];

        $byYear = [];
        $stats = ['rattaches' => 0, 'en_worklist' => 0];

        // Index des projets par nom normalisé (une passe pour tout le collect).
        $projetsParNom = Projet::query()->get(['id', 'nom'])
            ->groupBy(fn (Projet $p) => $this->ids->slug((string) $p->nom));

        foreach ($specs as $s) {
            $file = $this->download->downloadToCache($this->resolver->resolve($s['key']), "Fonds vert biodiversité {$s['year']}");
            $rows = $this->csv->read($file, ',');
            $hits = array_filter($rows, fn ($r) => $this->isAbc($r));
            $byYear[$s['year']] = count($hits);

            foreach ($hits as $r) {
                $nom = trim($r['nom_du_projet'] ?? '');
                if ($nom === '') {
                    continue;
                }
                $benef = trim($r['raison_sociale_beneficiaire'] ?? $r['nom_fournisseur'] ?? '');
                $idFv = $this->ids->projetId($nom, $benef !== '' ? $benef : null, $s['year']);

                $projet = Projet::find($idFv);

                if (! $projet) {
                    $alias = ProjectAlias::where('alias_project_id', $idFv)->first();
                    $projet = $alias ? Projet::find($alias->project_id) : null;
                }

                $ambigus = null;
                if (! $projet) {
                    $candidats = $projetsParNom->get($this->ids->slug($nom), new Collection);
                    if ($candidats->count() === 1) {
                        $projet = $candidats->first();
                    } elseif ($candidats->count() > 1) {
                        $ambigus = $candidats;
                    }
                }

                if ($projet) {
                    $this->rattacher($projet, $s['year'], $benef, $r);
                    $stats['rattaches']++;

                    continue;
                }

                $this->mettreEnWorklist($s['year'], $nom, $benef, $r, $ambigus);
                $stats['en_worklist']++;
            }
        }

        return ['total' => array_sum($byYear), 'by_year' => $byYear] + $stats;
    }

    /**
     * Crée ou met à jour le financement, marque le projet comme vu dans la
     * source (protection contre la suppression ciblée).
     */
    private function rattacher(Projet $projet, int $year, string $benef, array $r): void
    {
        $this->projects->markIngested($projet->id);

        Financement::updateOrCreate(
            [
                'project_id' => $projet->id,
                'source' => "fondsvert-p113-{$year}",
                'ligne_id' => $this->ligneId($r),
            ],
            [
                'beneficiaire' => $benef !== '' ? $benef : null,
                'annee_financement' => $year,
                'montant' => isset($r['montant_engage']) && is_numeric(str_replace(',', '.', $r['montant_engage']))
                    ? (float) str_replace(',', '.', $r['montant_engage'])
                    : null,
                'ligne_brute' => $r,
            ],
        );
    }

    /**
     * Ligne ambiguë ou nouvelle → worklist de vérification, sans jamais créer
     * de projet :
     * - les projets candidats reçoivent une vérification etat=a_verifier
     *   (visible dans /verify), sans écraser un verdict humain existant ;
     * - chaque ligne est journalisée dans audit_log (idempotent par ligne).
     */
    private function mettreEnWorklist(int $year, string $nom, string $benef, array $r, ?Collection $ambigus): void
    {
        $ligneId = $this->ligneId($r);
        $marqueur = "Fonds vert P113 {$year} ligne {$ligneId}";
        $detail = $benef !== '' ? " (bénéficiaire : {$benef})" : '';

        if ($ambigus && $ambigus->isNotEmpty()) {
            foreach ($ambigus as $candidat) {
                $note = "Doublon suspect ({$marqueur}) : « {$nom} »{$detail} semble financer ce projet, mais d'autres projets portent le même nom.";
                $verification = Verification::find($candidat->id);
                if (! $verification) {
                    Verification::create(['projet_id' => $candidat->id, 'etat' => 'a_verifier', 'note' => $note]);
                } elseif (! str_contains((string) $verification->note, $marqueur)) {
                    $verification->update([
                        'note' => trim(($verification->note ? $verification->note."\n" : '').$note),
                    ]);
                }
            }
        }

        $dejaJournalisee = AuditLog::where('action', 'worklist_fondsvert')
            ->where('apres', 'like', '%'.$ligneId.'%')
            ->exists();
        if (! $dejaJournalisee) {
            AuditLog::create([
                'action' => 'worklist_fondsvert',
                'avant' => null,
                'apres' => json_encode([
                    'ligne_id' => $ligneId,
                    'annee' => $year,
                    'nom' => $nom,
                    'beneficiaire' => $benef !== '' ? $benef : null,
                    'ambigu' => $ambigus ? $ambigus->pluck('id')->all() : false,
                    'ligne' => $r,
                ], JSON_UNESCAPED_UNICODE),
                'par_admin' => 'FondsVertCollector',
            ]);
        }
    }

    /**
     * Identifiant stable de la ligne : n° de dossier si présent, sinon
     * empreinte du contenu.
     */
    private function ligneId(array $r): string
    {
        $dossier = trim($r['numero_dossier_ds'] ?? '');
        if ($dossier !== '') {
            return $dossier;
        }

        return sha1(json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    private function isAbc(array $r): bool
    {
        $hay = ($r['nom_du_projet'] ?? '').' '.($r['resume_du_projet'] ?? '').' '.($r['demarche'] ?? '');
        // Normalisation Unicode : sans NFC ni le drapeau /u, le « é » de
        // « biodiversité » ne matchait jamais en mode octets et le filtre
        // ratait toutes les lignes écrites « Atlas de la biodiversité
        // communale » sans le sigle « ABC » (racine des ~75 candidats
        // non ingérés de l'audit du 2026-10-21).
        $hay = \Normalizer::normalize($hay, \Normalizer::FORM_C) ?: $hay;
        if (preg_match('/abc\s*terre/i', $hay)) {
            return false; // "ABC Terre" : carbone des sols, à exclure
        }

        return preg_match('/atlas de la biodiversit[ée] communale/ui', $hay) === 1
            || preg_match('/\bABC\b/ui', $hay) === 1;
    }
}
