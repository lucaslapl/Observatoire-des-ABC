<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3 de l'audit du 2026-10-21 : abc:data-fix corrige les anomalies
 * (années invalides, projets scindés, statuts inconnus, Fonds vert 2024 non
 * localisés, worklist du registre incomplet, Tournefort), de manière
 * idempotente et journalisée. Aucune correction ne touche le travail humain.
 */
class DataFixCommandTest extends TestCase
{
    use DatabaseMigrations;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Cache isolé (jamais le vrai storage/app/abc/cache). Le fake HTTP
        // est déclaré test par test (Http::fake s'empile : un fake large
        // ici masquerait les réponses spécifiques).
        $this->cacheDir = sys_get_temp_dir().'/abc-datafix-test-'.uniqid();
        mkdir($this->cacheDir, 0777, true);
        config(['abc.cache_dir' => $this->cacheDir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir.'/*') ?: []);
        rmdir($this->cacheDir);
        parent::tearDown();
    }

    public function test_annee_invalide_recherchee_dans_le_registre_puis_mise_en_file(): void
    {
        // Le registre en cache porte lui-même l'année invalide (Redon « 1 »).
        $this->ecrireRegistre([['nom' => 'ABC de Test-Annee', 'annee_debut' => '1']]);

        $projet = Projet::create([
            'id' => 'abc-de-test-annee-test-annee-1',
            'nom' => 'ABC de Test-Annee',
            'annee_debut' => 1,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        // Le registre n'a pas d'année valide : vérification manuelle.
        $this->assertSame(1, $projet->refresh()->annee_debut, 'l\'année n\'est pas modifiée par script');
        $verification = Verification::find($projet->id);
        $this->assertNotNull($verification);
        $this->assertSame('a_verifier', $verification->etat);
        $this->assertStringContainsString('année invalide', $verification->note);

        // Journalisée.
        $this->assertDatabaseHas('audit_log', ['action' => 'data_fix:annee_a_verifier']);
    }

    public function test_annee_invalide_corrigee_quand_le_registre_porte_une_annee_valide(): void
    {
        // Deux lignes registre : une invalide, une correcte pour le même nom.
        $this->ecrireRegistre([
            ['nom' => 'ABC de Test-Annee', 'annee_debut' => '2029'],
            ['nom' => 'ABC de Test-Annee', 'annee_debut' => '2019'],
        ]);

        $projet = Projet::create([
            'id' => 'abc-de-test-annee-test-annee-2029',
            'nom' => 'ABC de Test-Annee',
            'structure_porteuse' => 'Test-Annee',
            'annee_debut' => 2029,
            'statut' => 'a_venir',
            'source' => 'data.gouv',
        ]);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        $this->assertSame(2019, $projet->refresh()->annee_debut);
        $this->assertDatabaseHas('audit_log', ['action' => 'data_fix:annee_corrigee']);
        $this->assertDatabaseMissing('verifications', ['projet_id' => $projet->id]);
    }

    public function test_fusion_des_projets_scindes(): void
    {
        $ancien = Projet::create(['id' => 'ibc-de-garancieres-en-drouais-2014', 'nom' => 'IBC de Garancières-en-Drouais', 'annee_debut' => 2014, 'statut' => 'termine', 'source' => 'data.gouv']);
        $referent = Projet::create(['id' => 'ibc-de-garancieres-en-drouais-garancieres-en-drouais-2015', 'nom' => 'IBC de Garancières-en-Drouais', 'structure_porteuse' => 'Garancières-en-Drouais', 'annee_debut' => 2015, 'statut' => 'termine', 'source' => 'data.gouv']);
        Commune::create(['projet_id' => $ancien->id, 'code_geographique' => '28180']);
        Commune::create(['projet_id' => $referent->id, 'code_geographique' => '28180']);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        // Fusion effectuée via abc:merge-projects : alias + union, jamais de perte.
        $this->assertDatabaseHas('project_aliases', [
            'project_id' => $referent->id,
            'alias_project_id' => $ancien->id,
        ]);
        $this->assertSame(1, Commune::where('projet_id', $referent->id)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'fusion']);

        // Bagnères-de-Bigorre : éditions distinctes, jamais touchées.
        $bagnieres = Projet::create(['id' => 'abc-de-bagneres-de-bigorre-2010', 'nom' => 'ABC de Bagnères-de-Bigorre', 'annee_debut' => 2010, 'statut' => 'termine', 'source' => 'data.gouv']);
        $bagneries2 = Projet::create(['id' => 'abc-de-bagneres-de-bigorre-2024', 'nom' => 'ABC de Bagnères-de-Bigorre', 'annee_debut' => 2024, 'statut' => 'en_cours', 'source' => 'data.gouv']);
        $this->artisan('abc:data-fix')->assertExitCode(0);
        $this->assertDatabaseHas('projets', ['id' => $bagnieres->id]);
        $this->assertDatabaseHas('projets', ['id' => $bagneries2->id]);
        $this->assertDatabaseMissing('project_aliases', ['alias_project_id' => $bagnieres->id]);
    }

    public function test_statut_inconnu_passe_en_file(): void
    {
        $projet = Projet::create(['id' => 'abc-du-nord-de-test-martinique', 'nom' => 'ABC du Nord de la Martinique', 'statut' => 'inconnu', 'source' => 'data.gouv']);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        $verification = Verification::find($projet->id);
        $this->assertNotNull($verification);
        $this->assertStringContainsString('statut inconnu', $verification->note);
        $this->assertDatabaseHas('audit_log', ['action' => 'data_fix:statut_a_verifier']);
    }

    public function test_fonds_vert_2024_localise_quand_une_commune_sure_est_trouvee(): void
    {
        $projet = Projet::create([
            'id' => 'atlas-de-la-biodiversite-communale-commune-de-testville-2024',
            'nom' => 'ATLAS DE LA BIODIVERSITE COMMUNALE DE TESTVILLE',
            'structure_porteuse' => 'COMMUNE DE TESTVILLE',
            'source' => 'fondsvert-p113-2024',
            'statut' => 'a_venir',
        ]);

        Http::fake([
            'https://geo.api.gouv.fr/communes*' => Http::response([
                [
                    'code' => '31555',
                    'nom' => 'Testville',
                    'population' => 50000,
                    'centre' => ['coordinates' => [1.35, 43.6]],
                    'departement' => ['code' => '31', 'nom' => 'Haute-Garonne'],
                    'region' => ['code' => '76', 'nom' => 'Occitanie'],
                ],
            ], 200),
        ]);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        $this->assertSame(1, Commune::where('projet_id', $projet->id)->count());
        $commune = Commune::where('projet_id', $projet->id)->first();
        $this->assertSame('31555', $commune->code_geographique);
        $this->assertSame(43.6, $commune->lat);
        $this->assertSame('Haute-Garonne', $commune->libelle_departement);
        $this->assertDatabaseHas('geo_cache', ['code_geographique' => '31555']);
        $this->assertDatabaseHas('audit_log', ['action' => 'data_fix:commune_ajoutee']);
        // Localisé : pas de note « non localisé ».
        $this->assertDatabaseMissing('verifications', ['projet_id' => $projet->id]);
    }

    public function test_fonds_vert_2024_non_localise_passe_en_file(): void
    {
        $projet = Projet::create([
            'id' => 'atlas-de-la-biodiversite-communale-commune-de-nulle-part-2024',
            'nom' => 'ATLAS DE LA BIODIVERSITE COMMUNALE DU PAYS IMAGINAIRE',
            'structure_porteuse' => 'SM DU PAYS IMAGINAIRE',
            'source' => 'fondsvert-p113-2024',
            'statut' => 'a_venir',
        ]);

        // Sans correspondance sûre (réponse vide) : worklist, aucune création.
        Http::fake(['https://geo.api.gouv.fr/*' => Http::response([], 200)]);
        $this->artisan('abc:data-fix')->assertExitCode(0);

        $verification = Verification::find($projet->id);
        $this->assertNotNull($verification);
        $this->assertStringContainsString('non localisé', $verification->note);
        $this->assertSame(0, Commune::where('projet_id', $projet->id)->count());
    }

    public function test_registre_incomplet_mis_en_file_sans_modification(): void
    {
        $sansAnnee = Projet::create(['id' => 'abc-sans-annee-2024', 'nom' => 'ABC Sans-Annee', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        $sansStructure = Projet::create(['id' => 'abc-sans-structure-2024', 'nom' => 'ABC Sans-Structure', 'structure_porteuse' => null, 'annee_debut' => 2024, 'statut' => 'en_cours', 'source' => 'data.gouv']);

        $this->artisan('abc:data-fix')->assertExitCode(0);

        // Pas de modification des données : seulement des notes en worklist.
        $this->assertNull($sansAnnee->refresh()->annee_debut);
        $this->assertNull($sansStructure->refresh()->structure_porteuse);
        $this->assertStringContainsString('année absente du registre', Verification::find($sansAnnee->id)->note);
        $this->assertStringContainsString('structure porteuse absente', Verification::find($sansStructure->id)->note);
    }

    public function test_tournefort_marque_a_verifier_sans_etre_supprime(): void
    {
        $projet = Projet::create([
            'id' => 'abc-de-la-biodiversite-sur-le-territoire-de-tournefort-et-la-tour-commune-de-tournefort-2024',
            'nom' => 'Abc de la Biodiversité sur le territoire de TOURNEFORT et La TOUR',
            'source' => 'fondsvert-p113-2024',
            'statut' => 'a_venir',
        ]);
        $registreProche = Projet::create(['id' => 'abc-de-tournefort-et-la-tour-tournefort-2024', 'nom' => 'ABC de Tournefort et La Tour', 'annee_debut' => 2024, 'statut' => 'en_cours', 'source' => 'data.gouv']);

        Http::fake(['https://geo.api.gouv.fr/*' => Http::response([], 200)]);
        $this->artisan('abc:data-fix')->assertExitCode(0);

        $verification = Verification::find($projet->id);
        $this->assertNotNull($verification);
        $this->assertStringContainsString('origine à confirmer', $verification->note);
        $this->assertStringContainsString($registreProche->id, $verification->note);
        // Jamais supprimé.
        $this->assertDatabaseHas('projets', ['id' => $projet->id]);
    }

    public function test_deux_executions_successives_ne_changent_rien(): void
    {
        $this->ecrireRegistre([['nom' => 'ABC de Test-Annee', 'annee_debut' => '1']]);
        Projet::create(['id' => 'abc-de-test-annee-test-annee-1', 'nom' => 'ABC de Test-Annee', 'annee_debut' => 1, 'statut' => 'en_cours', 'source' => 'data.gouv']);
        Projet::create(['id' => 'ibc-de-garancieres-en-drouais-2014', 'nom' => 'IBC de Garancières-en-Drouais', 'annee_debut' => 2014, 'statut' => 'termine', 'source' => 'data.gouv']);
        Projet::create(['id' => 'ibc-de-garancieres-en-drouais-garancieres-en-drouais-2015', 'nom' => 'IBC de Garancières-en-Drouais', 'annee_debut' => 2015, 'statut' => 'termine', 'source' => 'data.gouv']);

        $this->artisan('abc:data-fix')->assertExitCode(0);
        $etatApresPremier = $this->etatBase();
        $this->artisan('abc:data-fix')->assertExitCode(0);

        $this->assertSame($etatApresPremier, $this->etatBase(), 'la seconde exécution ne doit rien changer');
    }

    // ------------------------------------------------------------------

    private function etatBase(): array
    {
        return [
            'projets' => Projet::pluck('id')->sort()->all(),
            'aliases' => ProjectAlias::pluck('alias_project_id')->sort()->all(),
            'verifications' => Verification::orderBy('projet_id')->get(['projet_id', 'etat', 'note'])->map(fn ($v) => $v->getAttributes())->all(),
            'audit' => AuditLog::count(),
        ];
    }

    /**
     * Écrit un petit registre OFB dans le cache (fichier attendu par
     * abc:data-fix pour chercher les années correctes).
     *
     * @param  array<int, array<string, string>>  $lignes
     */
    private function ecrireRegistre(array $lignes): void
    {
        $dir = config('abc.cache_dir');
        @mkdir($dir, 0777, true);
        $contenu = "nom;annee_debut\n";
        foreach ($lignes as $l) {
            $contenu .= $l['nom'].';'.$l['annee_debut']."\n";
        }
        file_put_contents($dir.'/atlas-biodiversite.csv', $contenu);
    }
}
