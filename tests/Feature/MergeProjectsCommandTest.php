<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Financement;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use App\Services\ProjetRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1 de l'audit du 2026-10-21 : fusion de deux projets dupliqués via
 * abc:merge-projects — union des données sans perte, alias + redirection
 * 301 de l'ancien slug, journal d'audit, snapshot de rollback, et
 * anti-réimport au collect.
 */
class MergeProjectsCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('abc');
        ProjetRepository::refreshExclusions();
    }

    private function canonique(): Projet
    {
        return Projet::create([
            'id' => 'abc-de-test-canonical-test-2021',
            'nom' => 'ABC de Test-Cano',
            'slug' => 'abc-de-test-cano',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2021,
        ]);
    }

    private function doublon(): Projet
    {
        return Projet::create([
            'id' => 'abc-de-test-doublon-test-2021',
            'nom' => 'ABC de Test-Doublon',
            'slug' => 'abc-de-test-doublon',
            'statut' => 'en_cours',
            'source' => 'wayback',
            'annee_debut' => 2021,
        ]);
    }

    public function test_fusion_unit_les_donnees_et_cree_un_alias(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        // Communes : A partagée, B propre au canonique, C propre au doublon.
        foreach ([['A', '11111'], ['B', '22222']] as [$libelle, $code]) {
            Commune::create([
                'projet_id' => $canonique->id,
                'code_geographique' => $code,
                'libelle_geographique' => 'Commune '.$libelle,
            ]);
        }
        Commune::create([
            'projet_id' => $doublon->id,
            'code_geographique' => '11111',
            'libelle_geographique' => 'Commune A (variante doublon)',
        ]);
        Commune::create([
            'projet_id' => $doublon->id,
            'code_geographique' => '33333',
            'libelle_geographique' => 'Commune C',
        ]);

        // Snapshots : date partagée (conflit) + date propre au doublon.
        Snapshot::create(['snapshot_date' => '2022-01-01', 'projet_id' => $canonique->id, 'avancement' => 'En cours', 'source' => 'data.gouv']);
        Snapshot::create(['snapshot_date' => '2022-01-01', 'projet_id' => $doublon->id, 'avancement' => 'Inconnu', 'source' => 'wayback']);
        Snapshot::create(['snapshot_date' => '2022-06-01', 'projet_id' => $doublon->id, 'avancement' => 'En cours', 'source' => 'wayback']);

        Verification::create(['projet_id' => $doublon->id, 'etat' => 'confirme_date', 'note' => 'vérifié par un humain']);
        Contribution::create(['projet_id' => $doublon->id, 'type' => 'date_debut', 'payload_json' => ['annee_debut' => 2021], 'statut' => 'validee']);

        $this->artisan('abc:merge-projects', [
            'doublon' => $doublon->id,
            'canonique' => $canonique->id,
            '--motif' => 'doublon wayback du registre (triage 2026-10-21)',
        ])->assertExitCode(0);

        // Le doublon n'est plus un projet ; le canonique reste.
        $this->assertDatabaseMissing('projets', ['id' => $doublon->id]);
        $this->assertDatabaseHas('projets', ['id' => $canonique->id]);

        // Union des communes dédupliquée sur le code INSEE (3 lignes, la
        // valeur du canonique conservée pour le code partagé).
        $this->assertSame(3, Commune::where('projet_id', $canonique->id)->count());
        $this->assertDatabaseHas('communes', [
            'projet_id' => $canonique->id,
            'code_geographique' => '11111',
            'libelle_geographique' => 'Commune A',
        ]);
        $this->assertDatabaseHas('communes', [
            'projet_id' => $canonique->id,
            'code_geographique' => '33333',
        ]);

        // Union des snapshots dédupliquée sur la date.
        $this->assertSame(2, Snapshot::where('projet_id', $canonique->id)->count());
        $this->assertTrue(
            Snapshot::where('projet_id', $canonique->id)->whereDate('snapshot_date', '2022-06-01')->exists()
        );

        // Travail humain migré, jamais perdu.
        $this->assertDatabaseHas('verifications', ['projet_id' => $canonique->id, 'etat' => 'confirme_date']);
        $this->assertDatabaseHas('contributions', ['projet_id' => $canonique->id, 'statut' => 'validee']);

        // Alias créé avec le slug et l'id historiques.
        $this->assertDatabaseHas('project_aliases', [
            'project_id' => $canonique->id,
            'alias_project_id' => $doublon->id,
            'alias_slug' => 'abc-de-test-doublon',
            'source' => 'wayback',
            'created_by' => 'abc:merge-projects',
        ]);

        // Journal d'audit.
        $audit = AuditLog::where('action', 'fusion')->first();
        $this->assertNotNull($audit);
        $apres = json_decode($audit->apres, true);
        $this->assertSame($canonique->id, $apres['canonique']['id']);
        $this->assertSame('doublon wayback du registre (triage 2026-10-21)', $apres['motif']);

        // Snapshot de rollback écrit (état complet du doublon).
        $files = Storage::disk('abc')->files('merges');
        $this->assertCount(1, $files);
        $snapshot = json_decode(Storage::disk('abc')->get($files[0]), true);
        $this->assertSame($doublon->id, $snapshot['projet']['id']);
        $this->assertSame(2, count($snapshot['communes']));
    }

    public function test_les_financements_du_doublon_migrent_vers_le_canonique(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        Financement::create([
            'project_id' => $doublon->id,
            'source' => 'fondsvert-p113-2024',
            'ligne_id' => '19848031',
            'beneficiaire' => 'CC DE TEST',
            'annee_financement' => 2024,
            'montant' => 111188.00,
            'ligne_brute' => ['nom_du_projet' => 'ABC de Test-Doublon'],
        ]);

        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $canonique->id])->assertExitCode(0);

        // Le financement a migré : la cascade de suppression du doublon ne
        // doit jamais effacer une ligne Fonds vert déjà rattachée.
        $this->assertDatabaseMissing('financements', ['project_id' => $doublon->id]);
        $this->assertSame(1, Financement::where('project_id', $canonique->id)->count());
        $this->assertDatabaseHas('financements', [
            'project_id' => $canonique->id,
            'source' => 'fondsvert-p113-2024',
            'ligne_id' => '19848031',
        ]);

        // Snapshot de rollback : l'état complet du financement est conservé.
        $files = Storage::disk('abc')->files('merges');
        $this->assertCount(1, $files);
        $snapshot = json_decode(Storage::disk('abc')->get($files[0]), true);
        $this->assertCount(1, $snapshot['financements']);
        $this->assertSame('19848031', $snapshot['financements'][0]['ligne_id']);
    }

    public function test_ancien_slug_redirige_en_301_vers_le_canonique(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $canonique->id])->assertExitCode(0);

        $this->get('/abc/abc-de-test-doublon')
            ->assertStatus(301)
            ->assertRedirect('/abc/abc-de-test-cano');

        $this->get('/abc/abc-de-test-cano')->assertOk();

        // Un slug inconnu reste un 404.
        $this->get('/abc/n-existe-pas')->assertNotFound();
    }

    public function test_la_fusion_refuse_les_cas_invalides(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        // Même id pour les deux.
        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $doublon->id])->assertExitCode(1);

        // Doublon introuvable.
        $this->artisan('abc:merge-projects', ['doublon' => 'id-inconnu', 'canonique' => $canonique->id])->assertExitCode(1);

        // Canonique introuvable.
        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => 'id-inconnu'])->assertExitCode(1);

        // Refus de fusionner un projet déjà alias.
        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $canonique->id])->assertExitCode(0);
        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => 'id-autre'])->assertExitCode(1);

        // Refus de fusionner DANS un projet déjà alias.
        $autre = Projet::create(['id' => 'abc-de-test-autre-2022', 'nom' => 'ABC de Test-Autre', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        $this->artisan('abc:merge-projects', ['doublon' => $autre->id, 'canonique' => $doublon->id])->assertExitCode(1);
        $this->assertDatabaseHas('projets', ['id' => $autre->id]);
    }

    public function test_deux_verifications_sont_unifiees_sans_perte(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        Verification::create(['projet_id' => $canonique->id, 'etat' => 'confirme_termine', 'note' => 'note canonique']);
        Verification::create(['projet_id' => $doublon->id, 'etat' => 'confirme_date', 'note' => 'note doublon']);

        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $canonique->id])->assertExitCode(0);

        $this->assertSame(1, Verification::where('projet_id', $canonique->id)->count());
        $this->assertDatabaseHas('verifications', ['projet_id' => $canonique->id, 'etat' => 'confirme_termine']);
        $this->assertStringContainsString('note canonique', Verification::find($canonique->id)->note);
        $this->assertStringContainsString('note doublon', Verification::find($canonique->id)->note);
    }

    public function test_le_collect_ne_reintroduit_pas_un_projet_fusionne(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        $this->artisan('abc:merge-projects', ['doublon' => $doublon->id, 'canonique' => $canonique->id])->assertExitCode(0);
        ProjetRepository::refreshExclusions();

        $repository = app(ProjetRepository::class);

        // La source ré-apporte le doublon : il ne doit pas être recréé.
        $repository->upsertProjet([
            'id' => $doublon->id,
            'nom' => 'ABC de Test-Doublon (ré-ingéré)',
            'statut' => 'en_cours',
            'source' => 'wayback',
            'communes' => [['code_geographique' => '99999']],
        ]);
        $repository->recordSnapshot('2026-10-22', $doublon->id, 'En cours', 'wayback');

        $this->assertDatabaseMissing('projets', ['id' => $doublon->id]);
        $this->assertSame(0, Commune::where('projet_id', $doublon->id)->count());
        $this->assertSame(0, Snapshot::where('projet_id', $doublon->id)->count());

        // Le canonique reste upsertable normalement.
        $repository->upsertProjet([
            'id' => $canonique->id,
            'nom' => 'ABC de Test-Cano',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        $this->assertDatabaseHas('projets', ['id' => $canonique->id, 'statut' => 'termine']);
    }

    public function test_une_paire_mise_en_file_apparait_dans_la_worklist(): void
    {
        $canonique = $this->canonique();
        $doublon = $this->doublon();

        // Un projet sans flags (ni wayback, ni année manquante, ni
        // potentiellement_*) : invisible de la worklist par défaut.
        Verification::create([
            'projet_id' => $canonique->id,
            'etat' => 'a_verifier',
            'note' => 'Doublon suspect avec '.$doublon->id.' : vérifier si édition distincte.',
        ]);

        $response = $this->get('/verify');
        $response->assertOk();
        $this->assertTrue(
            collect($response->inertiaProps()['projets'] ?? [])->pluck('id')->contains($canonique->id),
            'Le projet explicitement mis en file doit apparaître dans /verify'
        );
    }
}
