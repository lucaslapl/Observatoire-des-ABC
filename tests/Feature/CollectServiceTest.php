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

    public function test_purge_preserves_human_work_tables(): void
    {
        $projet = Projet::create([
            'id' => 'projet-test',
            'nom' => 'ABC Test',
            'statut' => 'a_verifier',
            'source' => 'data.gouv',
        ]);

        $verification = Verification::create([
            'projet_id' => $projet->id,
            'etat' => 'confirme_date',
            'verifie_le' => now(),
        ]);

        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'type' => 'statut',
            'payload_json' => ['statut' => 'en_cours'],
        ]);

        app(CollectService::class)->purgeReplicableData();

        $this->assertDatabaseMissing('projets', ['id' => $projet->id]);
        $this->assertDatabaseHas('verifications', ['projet_id' => $projet->id]);
        $this->assertDatabaseHas('contributions', ['id' => $contribution->id]);
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
