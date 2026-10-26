<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApiV1JournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_journal_est_integral_dans_le_meme_ordre_que_sur_le_site(): void
    {
        $projet = Projet::create([
            'id' => 'abc-journal',
            'nom' => 'ABC de Vannes',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56260',
            'libelle_geographique' => 'Vannes',
            'anomalie' => false,
        ]);

        $user = User::factory()->create(['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.org']);
        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'statut',
            'payload_json' => ['statut' => 'termine'],
            'ip' => '203.0.113.9',
            'statut' => 'validee',
        ]);

        $this->journalise('verification', ['projet_id' => $projet->id, 'etat' => 'confirme_termine'], '2026-01-01 10:00:00', parAdmin: 'Luc Admin');
        $this->journalise('validee', ['projet_id' => $projet->id, 'statut' => 'termine'], '2026-01-02 10:00:00', contributionId: $contribution->id);
        $this->journalise('fusion', ['canonique' => ['id' => $projet->id]], '2026-01-03 10:00:00', parAdmin: 'abc:merge-projects');

        // Entrée d'une action jamais publiée : elle ne doit pas apparaître.
        $this->journalise('annulee', ['projet_id' => $projet->id], '2026-01-04 10:00:00');

        $response = $this->getJson('/api/v1/journal');
        $response->assertOk();

        $data = $response->json('data');

        // Même ensemble d'actions que l'historique des fiches, plus récent d'abord.
        $this->assertCount(3, $data);
        $this->assertSame('fusion', $this->action($data[0]));
        $this->assertSame('validee', $this->action($data[1]));
        $this->assertSame('verification', $this->action($data[2]));

        // Les projets sont rattachables à leur fiche.
        $this->assertSame('abc-journal', $data[0]['projet_id']);
        $this->assertSame('Vannes', $data[0]['commune']);
        $this->assertSame('Etalab-2.0', $response->json('meta.licence'));
    }

    public function test_les_auteurs_sont_anonymises_credit_collectif(): void
    {
        $projet = Projet::create([
            'id' => 'abc-anonyme',
            'nom' => 'ABC de Vannes',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $user = User::factory()->create(['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.org']);
        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'date_debut',
            'payload_json' => ['annee_debut' => 2021],
            'ip' => '203.0.113.9',
            'statut' => 'validee',
        ]);

        // Contribution validée par un admin nommé, vérification par un admin nommé,
        // fusion automatique par une commande artisan.
        $this->journalise('validee', ['projet_id' => $projet->id], '2026-01-01 10:00:00', contributionId: $contribution->id);
        $this->journalise('verification', ['projet_id' => $projet->id, 'etat' => 'confirme_termine'], '2026-01-02 10:00:00', parAdmin: 'Luc Admin');
        $this->journalise('sync_dates', ['projet_id' => $projet->id, 'annee_debut' => 2021, 'annee_fin' => 2024], '2026-01-03 10:00:00', parAdmin: 'abc:sync-confirmed-dates');

        $response = $this->getJson('/api/v1/journal');
        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame("les contributeurs de l'Observatoire des ABC", $data[2]['auteur']);
        $this->assertSame("l'équipe de l'Observatoire des ABC", $data[1]['auteur']);
        $this->assertSame('Synchronisation automatique', $data[0]['auteur']);

        // Aucun nom ni email ne fuit.
        $this->assertStringNotContainsString('Jeanne Dupont', $response->getContent());
        $this->assertStringNotContainsString('jeanne@example.org', $response->getContent());
        $this->assertStringNotContainsString('Luc Admin', $response->getContent());
    }

    public function test_le_journal_est_pagine_par_curseur(): void
    {
        $projet = Projet::create([
            'id' => 'abc-page',
            'nom' => 'ABC de Vannes',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->journalise('verification', ['projet_id' => $projet->id, 'etat' => 'confirme_termine'], "2026-01-0{$i} 10:00:00");
        }

        $page1 = $this->getJson('/api/v1/journal?per_page=2');
        $page1->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $this->assertNotNull($page1->json('meta.next_cursor'));

        $page2 = $this->getJson('/api/v1/journal?per_page=2&cursor='.urlencode($page1->json('meta.next_cursor')));
        $page2->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }

    public function test_lhistorique_de_fiche_est_aussi_anonymise(): void
    {
        $projet = Projet::create([
            'id' => 'abc-fiche',
            'nom' => 'ABC de Vannes',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $user = User::factory()->create(['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.org']);
        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'note',
            'payload_json' => ['note' => 'Note publique.'],
            'ip' => '203.0.113.9',
            'statut' => 'validee',
        ]);
        $this->journalise('validee', ['projet_id' => $projet->id], '2026-01-01 10:00:00', contributionId: $contribution->id);
        $this->journalise('verification', ['projet_id' => $projet->id, 'etat' => 'confirme_termine'], '2026-01-02 10:00:00', parAdmin: 'Luc Admin');

        $response = $this->getJson('/api/v1/projets/abc-fiche');
        $response->assertOk();

        $historique = $response->json('data.historique');
        $this->assertSame("les contributeurs de l'Observatoire des ABC", $historique[1]['auteur']);
        $this->assertSame("l'équipe de l'Observatoire des ABC", $historique[0]['auteur']);
        $this->assertStringNotContainsString('Jeanne Dupont', $response->getContent());
    }

    private function journalise(string $action, ?array $apres, string $quand, ?int $contributionId = null, ?string $parAdmin = null): AuditLog
    {
        $log = AuditLog::create([
            'contribution_id' => $contributionId,
            'action' => $action,
            'apres' => $apres ? json_encode($apres) : null,
            'par_admin' => $parAdmin,
        ]);
        $log->created_at = Carbon::parse($quand);
        $log->save();

        return $log->refresh();
    }

    /**
     * Identifie une entrée via son libellé affiché sur le site (stable).
     *
     * @param  array<string, mixed>  $entry
     */
    private function action(array $entry): string
    {
        return (string) collect([
            'Doublon fusionné' => 'fusion',
            'Statut corrigé (contribution validée)' => 'validee',
            'Statut vérifié : terminé' => 'verification',
        ])->get($entry['label'], $entry['label']);
    }
}
