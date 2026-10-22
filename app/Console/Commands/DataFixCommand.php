<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\GeoCache;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\CsvReaderService;
use App\Services\ProjectIdService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Corrections de données scriptées de l'audit du 2026-10-21 (phase 3).
 *
 * Idempotent : deux exécutions successives ne changent rien (les notes de
 * vérification portent un marqueur, les fusions refusent les alias, les
 * communes ne sont créées que si absentes). Journalisé : chaque action
 * écrite dans audit_log (action data_fix).
 *
 * Rappel de l'audit — ce qui n'est PAS touché par cette commande :
 * - les 11 séries d'éditions distinctes (dont Bagnères-de-Bigorre) ;
 * - les 32 lignes sans année et 347 sans structure porteuse du registre :
 *   mises en file /verify, jamais corrigées par script ;
 * - le projet Tournefort : marqué à vérifier, jamais supprimé.
 */
class DataFixCommand extends Command
{
    protected $signature = 'abc:data-fix {--dry-run : lister les corrections sans les écrire}';

    protected $description = 'Corrige les anomalies identifiées par l\'audit du 2026-10-21 (idempotent, journalisé)';

    private const MARQUEUR = 'Correction audit 2026-10-21';

    /** Projets scindés du registre à fusionner (phase 1 : abc:merge-projects). */
    private const FUSIONS = [
        [
            'doublon' => 'ibc-de-garancieres-en-drouais-2014',
            'canonique' => 'ibc-de-garancieres-en-drouais-garancieres-en-drouais-2015',
            'motif' => 'Projet scindé du registre (2014/2015) : même ABC de Garancières-en-Drouais. La ligne 2015, qui porte la structure porteuse, est la référence.',
        ],
        [
            'doublon' => 'abc-de-vendome-vendome-2011',
            'canonique' => 'abc-de-vendome-vendome-2010',
            'motif' => 'Projet scindé du registre (2010/2011) : même ABC de Vendôme. La première année de l\'ABC est la référence.',
        ],
        // Bagnères-de-Bigorre (2010/2024) : éditions DISTINCTES, ne pas fusionner.
    ];

    /** Projet dont l'origine est à documenter (absent des sources actuelles). */
    private const TOURNEFORT_FV = 'abc-de-la-biodiversite-sur-le-territoire-de-tournefort-et-la-tour-commune-de-tournefort-2024';

    /** @var array<string, int> compteurs par catégorie */
    private array $rapport = [];

    private bool $dryRun;

    public function handle(CsvReaderService $csv, ProjectIdService $ids): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->rapport = array_fill_keys(
            ['annees_invalides', 'fusions', 'statuts_inconnus', 'localises', 'non_localises', 'sans_annee', 'sans_structure', 'tournefort'],
            0
        );

        $this->fixAnneesInvalides($csv);
        $this->fixFusionsScindees();
        $this->fixStatutsInconnus();
        $this->fixFondsVert2024NonLocalises($ids);
        $this->queueRegistreSansAnnee();
        $this->queueRegistreSansStructure();
        $this->queueTournefort($ids);

        foreach ($this->rapport as $categorie => $n) {
            $this->line(str_pad($categorie, 18)." {$n}");
        }
        $this->info($this->dryRun
            ? '--dry-run : aucun changement écrit.'
            : 'Corrections appliquées et journalisées (audit_log, action data_fix).');

        return self::SUCCESS;
    }

    /**
     * Années invalides (ex. Redon « 1 », Baie-Mahault « 2029 ») : recherche
     * de l'année correcte dans le registre source ; si le registre porte
     * lui-même l'erreur, mise en file de vérification manuelle.
     */
    private function fixAnneesInvalides(CsvReaderService $csv): void
    {
        $min = (int) config('abc.annee_min', 2000);
        $max = now()->year + 1;

        $registre = $this->lireRegistre($csv);

        Projet::query()
            ->whereNotNull('annee_debut')
            ->where(fn ($q) => $q->where('annee_debut', '<', $min)->orWhere('annee_debut', '>', $max))
            ->get()
            ->each(function (Projet $projet) use ($registre, $min, $max) {
                // Année correcte cherchée dans le registre (lignes du même nom).
                $correctes = [];
                foreach ($registre as $r) {
                    if (($r['nom'] ?? '') !== '' && $r['nom'] === $projet->nom
                        && ($r['annee_debut'] ?? '') !== '') {
                        $annee = (int) $r['annee_debut'];
                        if ($annee >= $min && $annee <= $max) {
                            $correctes[$annee] = true;
                        }
                    }
                }

                if (count($correctes) === 1 && key($correctes) !== $projet->annee_debut) {
                    $ancienne = $projet->annee_debut;
                    $nouvelle = (int) key($correctes);
                    $this->appliquer(fn () => $projet->update(['annee_debut' => $nouvelle]));
                    $this->journaliser('annee_corrigee', $projet, [
                        'avant' => $ancienne,
                        'apres' => $nouvelle,
                        'source' => 'registre OFB',
                    ]);
                    $this->rapport['annees_invalides']++;

                    return;
                }

                // Le registre porte lui-même l'erreur : vérification humaine.
                $note = self::MARQUEUR." — année invalide « {$projet->annee_debut} » : le registre OFB porte cette valeur ; année à corriger après vérification manuelle.";
                if ($this->mettreEnFile($projet->id, 'année invalide', $note)) {
                    $this->journaliser('annee_a_verifier', $projet, ['annee_invalide' => $projet->annee_debut]);
                    $this->rapport['annees_invalides']++;
                }
            });
    }

    /**
     * Projets scindés du registre (Garancières-en-Drouais, Vendôme) :
     * fusion via la commande de phase 1. ABC Bagnères-de-Bigorre reste en
     * deux éditions (ne pas y toucher).
     */
    private function fixFusionsScindees(): void
    {
        foreach (self::FUSIONS as $f) {
            if (ProjectAlias::where('alias_project_id', $f['doublon'])->exists()) {
                continue; // déjà fusionné : idempotence, silence
            }

            $doublon = Projet::find($f['doublon']);
            $canonique = Projet::find($f['canonique']);

            if (! $doublon || ! $canonique) {
                $this->warn("Fusion ignorée : projet introuvable ({$f['doublon']} / {$f['canonique']})");

                continue;
            }

            if ($this->dryRun) {
                $this->line("[dry-run] fusion {$f['doublon']} -> {$f['canonique']}");
                $this->rapport['fusions']++;

                continue;
            }

            $code = Artisan::call('abc:merge-projects', [
                'doublon' => $f['doublon'],
                'canonique' => $f['canonique'],
                '--motif' => $f['motif'].' (audit 2026-10-21, phase 3).',
            ]);
            if ($code !== 0) {
                $this->error("Fusion en échec : {$f['doublon']} -> {$f['canonique']}");

                continue;
            }
            $this->rapport['fusions']++;
        }
    }

    /** Statuts inconnus (ex. ABC du Nord de la Martinique) → file /verify. */
    private function fixStatutsInconnus(): void
    {
        Projet::query()->where('statut', 'inconnu')->get()->each(function (Projet $projet) {
            $note = self::MARQUEUR.' — statut inconnu : à renseigner via vérification manuelle (registre OFB sans avancement exploitable).';
            if ($this->mettreEnFile($projet->id, 'statut inconnu', $note)) {
                $this->journaliser('statut_a_verifier', $projet, ['statut' => 'inconnu']);
                $this->rapport['statuts_inconnus']++;
            }
        });
    }

    /**
     * Les 16 projets Fonds vert 2024 non localisés : le fichier P113 2024
     * n'expose aucune colonne commune, l'ancien collector ne rattachait donc
     * aucune commune. On tente de localiser via geo.api.gouv.fr à partir de
     * la structure porteuse ou du nom du projet ; en cas d'échec, file /verify.
     */
    private function fixFondsVert2024NonLocalises(ProjectIdService $ids): void
    {
        Projet::query()
            ->where('source', 'fondsvert-p113-2024')
            ->whereNotIn('id', Commune::select('projet_id'))
            ->get()
            ->each(function (Projet $projet) use ($ids) {
                foreach ($this->candidatsCommune($projet) as $candidat) {
                    $commune = $this->resoudreCommune($candidat, $ids);
                    if ($commune === null) {
                        continue;
                    }

                    $this->appliquer(function () use ($projet, $commune) {
                        Commune::create([
                            'projet_id' => $projet->id,
                            'code_geographique' => $commune['code'],
                            'libelle_geographique' => $commune['nom'],
                            'departement' => $commune['departement_code'],
                            'libelle_departement' => $commune['departement_nom'],
                            'region' => $commune['region_nom'],
                            'lon' => $commune['lon'],
                            'lat' => $commune['lat'],
                        ]);
                        GeoCache::updateOrCreate(
                            ['code_geographique' => $commune['code']],
                            ['lon' => $commune['lon'], 'lat' => $commune['lat'], 'name' => $commune['nom']],
                        );
                    });
                    $this->journaliser('commune_ajoutee', $projet, [
                        'commune' => $commune['nom'],
                        'code' => $commune['code'],
                        'via' => $candidat,
                    ]);
                    $this->rapport['localises']++;

                    return;
                }

                $note = self::MARQUEUR.' — non localisé : le fichier Fonds vert P113 2024 n\'expose pas de commune et aucune correspondance sûre n\'a été trouvée ; commune à préciser manuellement.';
                if ($this->mettreEnFile($projet->id, 'non localisé', $note)) {
                    $this->journaliser('localisation_a_verifier', $projet, ['candidats' => $this->candidatsCommune($projet)]);
                    $this->rapport['non_localises']++;
                }
            });
    }

    /**
     * Les lignes du registre sans année ne sont PAS corrigées par script :
     * mise en file /verify pour traitement humain progressif.
     */
    private function queueRegistreSansAnnee(): void
    {
        Projet::query()
            ->where('source', 'data.gouv')
            ->whereNull('annee_debut')
            ->get()
            ->each(function (Projet $projet) {
                $note = self::MARQUEUR.' — année absente du registre OFB : à compléter après vérification manuelle.';
                if ($this->mettreEnFile($projet->id, 'année absente du registre', $note)) {
                    $this->rapport['sans_annee']++;
                }
            });
    }

    /** Idem pour les lignes sans structure porteuse. */
    private function queueRegistreSansStructure(): void
    {
        Projet::query()
            ->where('source', 'data.gouv')
            ->whereNull('structure_porteuse')
            ->get()
            ->each(function (Projet $projet) {
                $note = self::MARQUEUR.' — structure porteuse absente du registre OFB : à compléter par enrichissement manuel.';
                if ($this->mettreEnFile($projet->id, 'structure porteuse absente', $note)) {
                    $this->rapport['sans_structure']++;
                }
            });
    }

    /**
     * Origine du projet Tournefort (source Fonds vert 2024, absent du
     * registre OFB et des archives) : documentée et marquée à vérifier,
     * jamais supprimée.
     */
    private function queueTournefort(ProjectIdService $ids): void
    {
        $projet = Projet::find(self::TOURNEFORT_FV);
        if (! $projet) {
            return;
        }

        $registreProche = Projet::query()
            ->where('id', '!=', $projet->id)
            ->get()
            ->filter(fn (Projet $p) => str_contains($ids->slug((string) $p->nom), 'tournefort'))
            ->pluck('id')
            ->all();

        $note = self::MARQUEUR.' — origine à confirmer : projet absent du registre OFB et des archives wayback ; ligne Fonds vert P113 2024 (financement rattaché).'
            .($registreProche !== [] ? ' Projet registre au nom proche : '.implode(', ', $registreProche).' — vérifier s\'il s\'agit du même ABC.' : '');
        if ($this->mettreEnFile($projet->id, 'origine à confirmer', $note)) {
            $this->journaliser('origine_a_verifier', $projet, ['registre_proche' => $registreProche]);
            $this->rapport['tournefort']++;
        }
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    /**
     * Met en file /verify sans jamais écraser un verdict humain existant.
     * Renvoie true si quelque chose a changé (idempotence par marqueur).
     */
    private function mettreEnFile(string $projetId, string $marqueurCourt, string $note): bool
    {
        if ($this->dryRun) {
            $this->line("[dry-run] file /verify : {$projetId} ({$marqueurCourt})");

            return true;
        }

        $verification = Verification::find($projetId);
        if (! $verification) {
            Verification::create(['projet_id' => $projetId, 'etat' => 'a_verifier', 'note' => $note]);

            return true;
        }

        if (str_contains((string) $verification->note, $marqueurCourt)) {
            return false; // déjà en file : idempotence
        }

        $verification->update([
            'note' => trim(($verification->note ? $verification->note."\n" : '').$note),
        ]);

        return true;
    }

    private function journaliser(string $action, Projet $projet, array $payload): void
    {
        if ($this->dryRun) {
            return;
        }

        AuditLog::create([
            'action' => 'data_fix:'.$action,
            'avant' => json_encode(['projet' => $projet->id], JSON_UNESCAPED_UNICODE),
            'apres' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'par_admin' => 'abc:data-fix',
        ]);
    }

    private function appliquer(callable $callback): void
    {
        if (! $this->dryRun) {
            $callback();
        }
    }

    /**
     * Candidats « nom de commune » dérivés du nom du projet (dernier
     * « de … », plus précis) puis de la structure porteuse (préfixes
     * légaux retirés). Le nom du projet est testé en premier : il porte
     * la dénomination officielle de la commune, alors que la structure
     * porteuse est un libellé juridique parfois tronqué (ex. « COMMUNE
     * DE BUSSAC » pour Bussac-sur-Charente).
     *
     * @return array<int, string>
     */
    private function candidatsCommune(Projet $projet): array
    {
        $candidats = [];

        $nom = mb_strtolower((string) $projet->nom);
        if (($pos = mb_strrpos($nom, ' de ')) !== false) {
            $candidats[] = trim(mb_substr($projet->nom, $pos + 4));
        }

        $structure = trim((string) $projet->structure_porteuse);
        if ($structure !== '') {
            $candidats[] = $this->retirerPrefixes($structure);
        }

        return array_values(array_unique(array_filter($candidats, fn ($c) => $c !== '')));
    }

    private function retirerPrefixes(string $nom): string
    {
        $nom = trim($nom);
        do {
            $nettoye = preg_replace(
                '/^(communes?|communaut[ée]s?|de\s+communes|mairie|cc|sm|epci|petr|du|de|d|des|la|l|le|les|et)\s+/iu',
                '',
                $nom
            ) ?? $nom;
            $change = $nettoye !== $nom;
            $nom = $nettoye;
        } while ($change);

        return trim($nom);
    }

    /**
     * Résout un nom de commune via geo.api.gouv.fr. Confiance = égalité du
     * nom normalisé (slug) entre le candidat et le résultat : aucune
     * correspondance approximative n'est acceptée.
     *
     * @return array{code: string, nom: string, lon: float, lat: float, departement_code: ?string, departement_nom: ?string, region_nom: ?string}|null
     */
    private function resoudreCommune(string $candidat, ProjectIdService $ids): ?array
    {
        if ($this->dryRun) {
            return null; // en dry-run : aucun appel réseau, aucune écriture
        }

        try {
            $reponse = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['User-Agent' => config('abc.user_agent')])
                ->get('https://geo.api.gouv.fr/communes', [
                    'nom' => $candidat,
                    'fields' => 'code,nom,centre,population,departement,region',
                    'limit' => 10,
                ]);
            if (! $reponse->successful()) {
                return null;
            }

            $slugCandidat = $ids->slug($candidat);
            $meilleure = null;
            foreach ($reponse->json() as $commune) {
                if ($ids->slug((string) ($commune['nom'] ?? '')) !== $slugCandidat) {
                    continue; // pas de correspondance approximative
                }
                if ($meilleure === null || (int) ($commune['population'] ?? 0) > $meilleure['population']) {
                    $coordonnees = $commune['centre']['coordinates'] ?? null;
                    $meilleure = [
                        'population' => (int) ($commune['population'] ?? 0),
                        'code' => (string) $commune['code'],
                        'nom' => (string) $commune['nom'],
                        'lon' => isset($coordonnees[0]) ? (float) $coordonnees[0] : 0.0,
                        'lat' => isset($coordonnees[1]) ? (float) $coordonnees[1] : 0.0,
                        'departement_code' => $commune['departement']['code'] ?? null,
                        'departement_nom' => $commune['departement']['nom'] ?? null,
                        'region_nom' => $commune['region']['nom'] ?? null,
                    ];
                }
            }

            if ($meilleure !== null && ($meilleure['lon'] !== 0.0 || $meilleure['lat'] !== 0.0)) {
                return $meilleure;
            }

            usleep((int) config('abc.request_delay_ms') * 1000);
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function lireRegistre(CsvReaderService $csv): array
    {
        $fichier = config('abc.cache_dir').'/atlas-biodiversite.csv';
        if (! is_file($fichier)) {
            $this->warn('Registre OFB absent du cache : recherche d\'année correcte désactivée.');

            return [];
        }

        return $csv->read($fichier, ';');
    }
}
