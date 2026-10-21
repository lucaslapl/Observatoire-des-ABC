<?php

namespace Tests\Feature;

use App\Collectors\WaybackCollector;
use App\Models\Contribution;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\CollectService;
use App\Services\ContributionService;
use App\Services\ProjetRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use RuntimeException;
use Tests\TestCase;

class CollectServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        @unlink(config('abc.cache_dir').'/abcexport');
        parent::tearDown();
    }

    public function test_sync_delete_removes_stale_projects_without_human_work(): void
    {
        $stale = Projet::create([
            'id' => 'projet-stale',
            'nom' => 'ABC Stale',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        $kept = Projet::create([
            'id' => 'projet-recent',
            'nom' => 'ABC Récent',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
        ]);

        $deleted = app(CollectService::class)->deleteStaleProjects([$kept->id]);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('projets', ['id' => $stale->id]);
        $this->assertDatabaseHas('projets', ['id' => $kept->id]);
    }

    public function test_sync_delete_preserves_projects_with_human_work(): void
    {
        $withVerification = Projet::create([
            'id' => 'projet-verif',
            'nom' => 'ABC Verif',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Verification::create([
            'projet_id' => $withVerification->id,
            'etat' => 'confirme_date',
            'verifie_le' => now(),
        ]);

        $withContribution = Projet::create([
            'id' => 'projet-contrib',
            'nom' => 'ABC Contrib',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        Contribution::create([
            'projet_id' => $withContribution->id,
            'type' => 'note',
            'payload_json' => ['note' => 'x'],
        ]);

        // Seul le projet frais est dans les sources : les deux projets avec
        // travail humain ne le sont plus mais doivent survivre.
        $deleted = app(CollectService::class)->deleteStaleProjects(['projet-frais']);

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('verifications', ['projet_id' => $withVerification->id]);
        $this->assertDatabaseHas('contributions', ['projet_id' => $withContribution->id]);
        $this->assertDatabaseHas('projets', ['id' => $withVerification->id]);
        $this->assertDatabaseHas('projets', ['id' => $withContribution->id]);
    }

    public function test_sync_delete_unchanged_ingested_project_survives_with_old_updated_at(): void
    {
        // Régression 2026-10 : un projet ré-upserté sans changement de données
        // ne voit pas son updated_at rafraîchi ; la détection par horodatage
        // le considérait à tort comme disparu. La détection est désormais
        // fondée sur les ids réellement ingérés.
        $repo = app(ProjetRepository::class);
        ProjetRepository::resetIngested();

        $repo->upsertProjet([
            'id' => 'projet-identique',
            'nom' => 'ABC Identique',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Projet::where('id', 'projet-identique')->update(['updated_at' => now()->subYear()]);

        // Second upsert aux données identiques : updated_at ne bouge pas
        // (comportement d'Eloquent documenté), mais le projet est bien vu
        // dans la source et doit survivre à la suppression ciblée.
        $repo->upsertProjet([
            'id' => 'projet-identique',
            'nom' => 'ABC Identique',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        $this->assertTrue(Projet::find('projet-identique')->updated_at->lt(now()->subDay()));

        $deleted = app(CollectService::class)->deleteStaleProjects(ProjetRepository::ingestedIds());
        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('projets', ['id' => 'projet-identique']);
    }

    public function test_sync_delete_aborts_when_nothing_ingested(): void
    {
        Projet::create([
            'id' => 'projet-x',
            'nom' => 'ABC X',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->expectException(RuntimeException::class);
        app(CollectService::class)->deleteStaleProjects([]);
    }

    public function test_sync_delete_aborts_when_deletion_would_be_massive(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Projet::create([
                'id' => "projet-{$i}",
                'nom' => "ABC {$i}",
                'statut' => 'en_cours',
                'source' => 'data.gouv',
            ]);
        }

        // 2 projets vus dans les sources : supprimer les 8 autres (>50 %)
        // doit être refusé sans --force.
        $this->expectException(RuntimeException::class);
        app(CollectService::class)->deleteStaleProjects(['projet-1', 'projet-2']);
    }

    public function test_sync_delete_force_allows_massive_deletion(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Projet::create([
                'id' => "projet-{$i}",
                'nom' => "ABC {$i}",
                'statut' => 'en_cours',
                'source' => 'data.gouv',
            ]);
        }

        $deleted = app(CollectService::class)->deleteStaleProjects(['projet-1', 'projet-2'], force: true);

        $this->assertSame(8, $deleted);
        $this->assertDatabaseHas('projets', ['id' => 'projet-1']);
        $this->assertDatabaseMissing('projets', ['id' => 'projet-3']);
    }

    public function test_wayback_marks_existing_projects_as_ingested(): void
    {
        // Le collector wayback n'upsert que les projets inconnus : les
        // projets déjà présents doivent quand même être marqués ingérés
        // (sinon la suppression ciblée les effacerait).
        $dir = config('abc.cache_dir');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/abcexport',
            "nom,structure_porteuse,type_de_structure_porteuse,annee_debut,avancement,ami_ofb,ressource_documentaire,code_commune,commune\n".
            "ABC Historique,Mairie de Test,Collectivité,2019,,Oui,https://ex.fr,54395,Nancy\n"
        );

        $collector = app(WaybackCollector::class);

        ProjetRepository::resetIngested();
        $collector->collect();
        $this->assertDatabaseHas('projets', ['source' => 'wayback']);

        $waybackId = Projet::where('source', 'wayback')->value('id');

        ProjetRepository::resetIngested();
        $collector->collect();

        $ingested = ProjetRepository::ingestedIds();
        $this->assertContains($waybackId, $ingested);

        app(CollectService::class)->deleteStaleProjects($ingested);
        $this->assertDatabaseHas('projets', ['id' => $waybackId]);
    }

    public function test_collect_skips_excluded_projects(): void
    {
        ProjectExclusion::create(['projet_id' => 'projet-exclu']);

        ProjetRepository::refreshExclusions();
        $repo = app(ProjetRepository::class);
        $repo->upsertProjet([
            'id' => 'projet-exclu',
            'nom' => 'ABC Exclu',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        $repo->upsertProjet([
            'id' => 'projet-conserve',
            'nom' => 'ABC OK',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->assertDatabaseMissing('projets', ['id' => 'projet-exclu']);
        $this->assertDatabaseHas('projets', ['id' => 'projet-conserve']);
    }

    public function test_upsert_preserves_dates_from_validated_contribution(): void
    {
        Projet::create([
            'id' => 'projet-dates',
            'nom' => 'ABC Dates',
            'annee_debut' => 2020,
            'annee_fin' => 2021,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        $c = Contribution::create([
            'projet_id' => 'projet-dates',
            'type' => 'date_debut',
            'payload_json' => ['annee_debut_suggeree' => '2019', 'annee_fin_suggeree' => '2022'],
            'statut' => 'en_attente',
        ]);

        app(ContributionService::class)->applyContribution($c->id, 'admin');
        $this->assertDatabaseHas('projets', ['id' => 'projet-dates', 'annee_debut' => 2019, 'annee_fin' => 2022]);

        // Collect suivant : la source annonce une autre date, la valeur
        // humaine validée doit survivre (les autres champs suivent la source).
        $repo = app(ProjetRepository::class);
        $repo->upsertProjet([
            'id' => 'projet-dates',
            'nom' => 'ABC Dates renommé',
            'annee_debut' => 2020,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->assertDatabaseHas('projets', [
            'id' => 'projet-dates',
            'nom' => 'ABC Dates renommé',
            'annee_debut' => 2019,
            'annee_fin' => 2022,
        ]);
    }

    public function test_upsert_source_dates_win_without_validated_contribution(): void
    {
        Projet::create([
            'id' => 'projet-source',
            'nom' => 'ABC Source',
            'annee_debut' => 2020,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        // Contribution jamais traitée : aucune protection, la source prime.
        Contribution::create([
            'projet_id' => 'projet-source',
            'type' => 'date_debut',
            'payload_json' => ['annee_debut_suggeree' => '2019'],
            'statut' => 'en_attente',
        ]);

        app(ProjetRepository::class)->upsertProjet([
            'id' => 'projet-source',
            'nom' => 'ABC Source',
            'annee_debut' => 2021,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->assertDatabaseHas('projets', ['id' => 'projet-source', 'annee_debut' => 2021]);
    }

    public function test_upsert_source_dates_win_again_after_contribution_revert(): void
    {
        Projet::create([
            'id' => 'projet-revert',
            'nom' => 'ABC Revert',
            'annee_debut' => 2020,
            'annee_fin' => 2021,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        $c = Contribution::create([
            'projet_id' => 'projet-revert',
            'type' => 'date_debut',
            'payload_json' => ['annee_debut_suggeree' => '2019'],
            'statut' => 'en_attente',
        ]);

        $service = app(ContributionService::class);
        $service->applyContribution($c->id, 'admin');
        $service->revertContribution($c->id, 'admin');

        // Contribution retirée : la protection tombe, la source reprend la main.
        app(ProjetRepository::class)->upsertProjet([
            'id' => 'projet-revert',
            'nom' => 'ABC Revert',
            'annee_debut' => 2021,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->assertDatabaseHas('projets', ['id' => 'projet-revert', 'annee_debut' => 2021]);
    }
}
