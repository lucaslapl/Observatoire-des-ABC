<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\CollectService;
use App\Services\ProjetRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class CollectServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_sync_delete_removes_stale_projects_without_human_work(): void
    {
        $stale = Projet::create([
            'id' => 'projet-stale',
            'nom' => 'ABC Stale',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        // Simule un projet non ré-ingéré au dernier collect (updated_at non
        // fillable : on passe par le query builder).
        Projet::where('id', $stale->id)->update(['updated_at' => now()->subDay()]);

        app(CollectService::class)->deleteStaleProjects(now());

        $this->assertDatabaseMissing('projets', ['id' => $stale->id]);
    }

    public function test_sync_delete_preserves_projects_with_human_work(): void
    {
        $withVerification = Projet::create([
            'id' => 'projet-verif',
            'nom' => 'ABC Verif',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Projet::where('id', $withVerification->id)->update(['updated_at' => now()->subDay()]);
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
        Projet::where('id', $withContribution->id)->update(['updated_at' => now()->subDay()]);
        Contribution::create([
            'projet_id' => $withContribution->id,
            'type' => 'note',
            'payload_json' => ['note' => 'x'],
        ]);

        $reingested = Projet::create([
            'id' => 'projet-frais',
            'nom' => 'ABC Frais',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
        ]);

        $deleted = app(CollectService::class)->deleteStaleProjects(now());

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('projets', ['id' => $withVerification->id]);
        $this->assertDatabaseHas('projets', ['id' => $withContribution->id]);
        $this->assertDatabaseHas('projets', ['id' => $reingested->id]);
        $this->assertDatabaseHas('verifications', ['projet_id' => $withVerification->id]);
        $this->assertDatabaseHas('contributions', ['projet_id' => $withContribution->id]);
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
}
