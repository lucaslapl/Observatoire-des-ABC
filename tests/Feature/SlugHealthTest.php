<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Services\CollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Slugs de fiches (/abc/{slug}) — audit SEO du 2026-11-17 (C2) : des
 * fiches référencées renvoyaient 404 alors que le projet existe. Diagnostic
 * (abc:diagnostic:slugs), réparation (abc:fix:slugs), stabilité des slugs
 * sur les noms à apostrophes, et contrôle post-collect bloquant.
 */
class SlugHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nom avec parenthèses et apostrophe typographique (U+2019) : le vrai
     * cas SMPMO de l'audit. Le slug calculé doit être identique à celui
     * de la variante à apostrophe ASCII — jamais d'instabilité.
     */
    private const NOM_SMPTO_TYPO = 'ABC du Syndicat Mixte Plaines Monts d’Or (SMPMO)';

    private const NOM_SMPTO_ASCII = "ABC du Syndicat Mixte Plaines Monts d'Or (SMPMO)";

    private const SLUG_SMPTO = 'abc-du-syndicat-mixte-plaines-monts-dor-smpmo';

    public function test_slug_stable_sur_apostrophe_typographique(): void
    {
        $this->assertSame(self::SLUG_SMPTO, Str::slug(self::NOM_SMPTO_TYPO));
        $this->assertSame(Str::slug(self::NOM_SMPTO_ASCII), Str::slug(self::NOM_SMPTO_TYPO));

        // makeUniqueSlug (hook de sauvegarde) dérive le même slug pour les
        // deux variantes du nom : deux projets réels homonymes ne peuvent
        // pas produire de collision silencieuse — l'un reçoit le suffixe -2.
        $premier = Projet::create([
            'id' => 'smmpmo-typo-2023',
            'nom' => self::NOM_SMPTO_TYPO,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        $second = Projet::create([
            'id' => 'smmpmo-ascii-2023',
            'nom' => self::NOM_SMPTO_ASCII,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->assertSame(self::SLUG_SMPTO, $premier->slug);
        $this->assertSame(self::SLUG_SMPTO.'-2', $second->slug);
    }

    public function test_chaque_projet_avec_slug_rend_une_fiche_accessible(): void
    {
        foreach ([
            ['abc-de-murs-erigne-2020', 'ABC de Mûrs-Erigné', 'abc-de-murs-erigne'],
            ['abc-de-verneuil-sur-seine-verneuil-sur-seine-2024', 'ABC de Verneuil-sur-Seine', 'abc-de-verneuil-sur-seine'],
            ['abc-communaute-de-communes-flandres-lys-2024', 'ABC Communauté de Communes Flandres Lys', 'abc-communaute-de-communes-flandres-lys'],
            ['smmpmo-typo-2023', self::NOM_SMPTO_TYPO, self::SLUG_SMPTO],
        ] as [$id, $nom, $slug]) {
            $projet = Projet::create([
                'id' => $id,
                'nom' => $nom,
                'statut' => 'en_cours',
                'source' => 'data.gouv',
                'slug' => $slug,
            ]);

            $this->assertSame($slug, $projet->slug);
            $this->get('/abc/'.urlencode($slug))->assertOk();
            // La page patrimoine du périmètre suit la même resolution.
            $this->get('/abc/'.urlencode($slug).'/patrimoine')->assertOk();
        }
    }

    public function test_alias_connu_redirige_301_vers_la_fiche_canonique(): void
    {
        $canonique = Projet::create([
            'id' => 'abc-canonique-2020',
            'nom' => 'ABC de Canon',
            'slug' => 'abc-de-canon',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        ProjectAlias::create([
            'project_id' => $canonique->id,
            'alias_project_id' => null,
            'alias_slug' => 'abc-ancien-slug',
            'source' => 'data.gouv',
            'motif' => 'test',
            'created_by' => 'test',
        ]);

        $this->get('/abc/abc-ancien-slug')
            ->assertStatus(301)
            ->assertRedirect('/abc/abc-de-canon');
        // Même résolution d'alias pour la page patrimoine du périmètre.
        $this->get('/abc/abc-ancien-slug/patrimoine')
            ->assertStatus(301)
            ->assertRedirect('/abc/abc-de-canon/patrimoine');
        // L'alias inconnu reste une 404.
        $this->get('/abc/inconnu-au-bataillon')->assertNotFound();
    }

    /**
     * C2 : slug vidé en base (import SQL sans Eloquent, colonne non
     * rétro-remplie) alors que le projet a des communes.
     */
    private function projetSansSlug(): Projet
    {
        $projet = Projet::create([
            'id' => 'abc-verneuil-sur-seine-verneuil-sur-seine-2024',
            'nom' => 'ABC de Verneuil-sur-Seine',
            'slug' => 'abc-de-verneuil-sur-seine',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '78650',
            'libelle_geographique' => 'Verneuil-sur-Seine',
            'departement' => '78',
            'region' => 'Île-de-France',
            'anomalie' => false,
        ]);

        // L'URL historique répond 404 : le slug a disparu.
        Projet::query()->whereKey($projet->id)->update(['slug' => null]);

        return $projet;
    }

    public function test_diagnostic_liste_les_anomalies(): void
    {
        $sansSlug = $this->projetSansSlug();

        $this->artisan('abc:diagnostic:slugs')
            ->expectsOutputToContain('Projets sans slug')
            ->expectsOutputToContain($sansSlug->nom)
            ->assertExitCode(0);
    }

    public function test_fix_slugs_est_en_dry_run_par_defaut(): void
    {
        $sansSlug = $this->projetSansSlug();

        $this->artisan('abc:fix:slugs')
            ->expectsOutputToContain('Dry-run')
            ->expectsOutputToContain('abc-de-verneuil-sur-seine')
            ->assertExitCode(0);

        // Rien n'a été écrit.
        $this->assertDatabaseHas('projets', ['id' => $sansSlug->id, 'slug' => null]);
        $this->assertSame(0, ProjectAlias::count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'slugs_fix:slug_reattribue']);
    }

    public function test_fix_slugs_apply_reattribue_le_slug_et_journalise(): void
    {
        $sansSlug = $this->projetSansSlug();

        $this->artisan('abc:fix:slugs', ['--apply' => true])
            ->expectsOutputToContain('Réparations écrites')
            ->assertExitCode(0);

        $sansSlug->refresh();
        $this->assertSame('abc-de-verneuil-sur-seine', $sansSlug->slug);
        $this->get('/abc/abc-de-verneuil-sur-seine')->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'slugs_fix:slug_reattribue',
            'par_admin' => 'abc:fix:slugs',
        ]);

        // Idempotence : un second passage appliqué ne change rien.
        $this->artisan('abc:fix:slugs', ['--apply' => true])->assertExitCode(0);
        $this->assertSame('abc-de-verneuil-sur-seine', $sansSlug->refresh()->slug);
        $this->assertSame(1, AuditLog::where('action', 'slugs_fix:slug_reattribue')->count());
    }

    public function test_fix_slugs_ne_modifie_jamais_un_slug_existant_et_cree_un_alias(): void
    {
        // Slug hérité séquentiel de l'import SQLite : l'URL /abc/abc-454
        // fonctionne, mais celle que Google a indexée (/abc/abc-du-syndicat-…)
        // renvoie 404 — cas SMPMO de l'audit.
        $smmpmo = Projet::create([
            'id' => 'abc-du-syndicat-mixte-plaines-monts-d-or-smpmo-syndicat-mixte-plaines-monts-d-or-smpmo-2023',
            'nom' => self::NOM_SMPTO_TYPO,
            'slug' => 'abc-454',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->artisan('abc:fix:slugs', ['--apply' => true])
            ->expectsOutputToContain('alias créé')
            ->assertExitCode(0);

        // Le slug canonique n'a pas bougé : l'URL historique reste stable.
        $this->assertSame('abc-454', $smmpmo->refresh()->slug);
        $this->get('/abc/abc-454')->assertOk();

        // L'URL indexée du nom est réparée : 301 vers la fiche, jamais 404.
        $this->get('/abc/'.self::SLUG_SMPTO)
            ->assertStatus(301)
            ->assertRedirect('/abc/abc-454');

        $this->assertDatabaseHas('project_aliases', [
            'project_id' => $smmpmo->id,
            'alias_slug' => self::SLUG_SMPTO,
            'alias_project_id' => null,
            'created_by' => 'abc:fix:slugs',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'slugs_fix:alias_cree',
            'par_admin' => 'abc:fix:slugs',
        ]);
    }

    public function test_fix_slugs_signale_le_conflit_d_homonymes_sans_ecrire(): void
    {
        // Deux projets du même nom : le premier a gardé le slug calculé,
        // le second le suffixe -2 (collision saine d'homonymes).
        Projet::create(['id' => 'homonyme-a', 'nom' => 'ABC de Gennevilliers', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        $b = Projet::create(['id' => 'homonyme-b', 'nom' => 'ABC de Gennevilliers', 'statut' => 'termine', 'source' => 'wayback']);
        $this->assertSame('abc-de-gennevilliers-2', $b->slug);

        $this->artisan('abc:fix:slugs', ['--apply' => true])
            ->expectsOutputToContain('conflit')
            ->assertExitCode(0);

        // Le slug calculé appartient au premier projet : aucun alias n'est
        // créé pour le second (la fiche du premier gagne toujours) et
        // personne n'est modifié.
        $this->assertSame(0, ProjectAlias::count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'slugs_fix:alias_cree']);
        $this->get('/abc/abc-de-gennevilliers')->assertOk();
        $this->get('/abc/abc-de-gennevilliers-2')->assertOk();
    }

    public function test_collect_bloque_quand_un_projet_avec_communes_reste_sans_slug(): void
    {
        $sansSlug = $this->projetSansSlug();

        try {
            app(CollectService::class)->verifierFichesAccessibles();
            $this->fail('le contrôle post-collect devait signaler le projet sans slug');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('abc-verneuil-sur-seine-verneuil-sur-seine-2024', $e->getMessage());
            $this->assertStringContainsString('sans slug', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_log', [
            'action' => 'alerte_collect',
            'par_admin' => 'CollectService',
        ]);

        // Après réparation (abc:fix:slugs --apply), le contrôle repasse.
        $this->artisan('abc:fix:slugs', ['--apply' => true])->assertExitCode(0);
        app(CollectService::class)->verifierFichesAccessibles();
        $this->assertSame(1, AuditLog::where('action', 'alerte_collect')->count(), 'aucune nouvelle alerte une fois réparé');
    }
}
