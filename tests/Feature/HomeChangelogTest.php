<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeChangelogTest extends TestCase
{
    use RefreshDatabase;

    protected function projet(string $id, string $nom): Projet
    {
        return Projet::create([
            'id' => $id,
            'nom' => $nom,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ]);
    }

    protected function journalise(string $action, ?array $apres, ?array $avant = null, ?int $contributionId = null, ?string $quand = null, ?string $parAdmin = 'test'): AuditLog
    {
        $log = AuditLog::create([
            'contribution_id' => $contributionId,
            'action' => $action,
            'avant' => $avant ? json_encode($avant) : null,
            'apres' => $apres ? json_encode($apres) : null,
            'par_admin' => $parAdmin,
        ]);
        $log->created_at = $quand ? Carbon::parse($quand) : $log->created_at;
        $log->save();

        return $log->refresh();
    }

    public function test_home_journal_affiche_les_cinq_dernieres_modifications_et_paginate(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');

        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56250',
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => 'Bretagne',
            'anomalie' => false,
        ]);

        // Sept vérifications : 5 en page 1, 2 en page 2, la plus récente en tête.
        foreach (range(1, 7) as $i) {
            $this->journalise('verification', [
                'projet_id' => $projet->id,
                'nom' => $projet->nom,
                'etat' => 'confirme_date',
                'annee_debut' => 2019,
                'annee_fin' => 2023,
            ], quand: "2026-12-0{$i} 10:00:00");
        }

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Map', true)
                ->has('journal.items', 5)
                ->where('journal.page', 1)
                ->where('journal.last_page', 2)
                ->where('journal.total', 7)
                ->where('journal.has_prev', false)
                ->where('journal.has_next', true)
                ->where('journal.items.0.label', 'Dates confirmées')
                ->where('journal.items.0.detail', '2019-2023')
                ->where('journal.items.0.projet_slug', $projet->slug)
                ->where('journal.items.0.projet_nom', $projet->nom)
                ->where('journal.items.0.commune', 'Vannes')
                ->where('journal.items.0.auteur', 'test')
        );

        $this->get('/?journal_page=2')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Map')
                ->has('journal.items', 2)
                ->where('journal.page', 2)
                ->where('journal.has_prev', true)
                ->where('journal.has_next', false)
        );
    }

    public function test_home_journal_masque_les_corrections_automatiques(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');

        $this->journalise('verification', ['projet_id' => $projet->id, 'nom' => $projet->nom, 'etat' => 'confirme_termine'], quand: '2026-12-01 10:00:00');
        $this->journalise('sync_dates', ['projet_id' => $projet->id, 'annee_debut' => 2019, 'annee_fin' => 2023], quand: '2026-12-02 10:00:00', parAdmin: 'abc:sync-confirmed-dates');
        $this->journalise('data_fix:communes', ['n' => 1], ['projet' => $projet->id], quand: '2026-12-03 10:00:00', parAdmin: 'abc:data-fix');
        $this->journalise('fusion', ['canonique' => ['id' => $projet->id, 'nom' => $projet->nom, 'slug' => $projet->slug]], ['doublon' => ['id' => 'abc-doublon', 'nom' => 'ABC doublon']], quand: '2026-12-04 10:00:00', parAdmin: 'abc:merge-projects');
        $this->journalise('fusion', ['canonique' => ['id' => $projet->id, 'nom' => $projet->nom, 'slug' => $projet->slug]], ['doublon' => ['id' => 'abc-doublon', 'nom' => 'ABC doublon']], quand: '2026-12-05 10:00:00', parAdmin: 'Alice');

        // Accueil : seules les modifications manuelles apparaissent.
        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 2)
                ->where('journal.total', 2)
                ->where('journal.items.0.label', 'Doublon fusionné')
                ->where('journal.items.0.auteur', 'Alice')
                ->where('journal.items.1.label', 'Statut vérifié : terminé')
        );

        // Fiche projet : l'historique reste complet, corrections automatiques comprises.
        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('projet.historique', 5)
                ->where('projet.historique.0.label', 'Doublon fusionné')
                ->where('projet.historique.0.auteur', 'Alice')
                ->where('projet.historique.1.label', 'Doublon fusionné')
                ->where('projet.historique.1.auteur', 'Fusion automatique')
                ->where('projet.historique.2.label', 'Correction automatique (audit des données)')
                ->where('projet.historique.2.auteur', 'Audit automatique')
                ->where('projet.historique.3.label', 'Dates resynchronisées')
                ->where('projet.historique.3.auteur', 'Synchronisation automatique')
                ->where('projet.historique.4.label', 'Statut vérifié : terminé')
        );
    }

    public function test_home_journal_exclut_les_actions_internes(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');

        $this->journalise('alerte_collect', ['message' => '3 projets disparus'], quand: '2026-12-01 10:00:00');
        $this->journalise('worklist_fondsvert', ['n' => 12], quand: '2026-12-02 10:00:00');
        $this->journalise('data_fix:statut', ['projet_id' => $projet->id], quand: '2026-12-03 10:00:00');
        $this->journalise('refusee', null, quand: '2026-12-04 10:00:00');

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 0)
                ->where('journal.total', 0)
                ->where('journal.last_page', 1)
        );
    }

    public function test_home_journal_resout_une_contribution_validee_vers_son_projet(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');
        $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.fr', 'password' => 'x']);

        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'date_debut',
            'payload_json' => json_encode(['annee_debut_suggeree' => '2019']),
            'statut' => 'validee',
        ]);

        $this->journalise('validee', [
            'etat' => 'confirme_date',
            'annee_debut' => 2019,
            'annee_fin' => null,
        ], contributionId: $contribution->id, quand: '2026-12-05 10:00:00');

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.label', 'Dates corrigées (contribution validée)')
                ->where('journal.items.0.projet_nom', $projet->nom)
                ->where('journal.items.0.projet_slug', $projet->slug)
                ->where('journal.items.0.auteur', 'Jane Doe')
        );
    }

    public function test_home_journal_n_expose_pas_les_emails(): void
    {
        $log = AuditLog::create([
            'action' => 'projet_supprime',
            'avant' => json_encode(['projet_id' => 'abc-fantome', 'nom' => 'ABC fantôme']),
            'par_admin' => 'admin@example.fr',
        ]);
        $log->created_at = Carbon::parse('2026-12-06 10:00:00');
        $log->save();

        // L'email n'apparaît jamais ; un libellé neutre le remplace.
        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.auteur', 'un administrateur')
        );
    }

    /**
     * Un contributeur ayant coché « rendre mes contributions anonymes » sur son
     * profil est masqué dans les espaces publics, compte ou pas.
     */
    public function test_contribution_d_un_compte_anonyme_est_masquee(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');
        $user = User::create(['name' => 'Jean Nez', 'email' => 'j@e.fr', 'password' => 'x', 'contributions_anonymes' => true]);

        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'statut',
            'payload_json' => json_encode(['statut_suggere' => 'termine']),
            'statut' => 'validee',
        ]);

        $this->journalise('validee', ['etat' => 'confirme_termine'], contributionId: $contribution->id, quand: '2026-12-08 10:00:00');

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.auteur', 'un contributeur anonyme')
        );
        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('projet.historique', 1)
                ->where('projet.historique.0.auteur', 'un contributeur anonyme')
        );
    }

    /**
     * Une contribution d'avant l'espace membre (sans compte lié) : le nom du
     * contributeur est inconnu, celui du valideur ne le remplace jamais.
     */
    public function test_contribution_sans_compte_reste_anonyme(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');

        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'type' => 'date_debut',
            'payload_json' => json_encode(['annee_debut_suggeree' => '2019']),
            'statut' => 'validee',
        ]);

        $this->journalise('validee', ['etat' => 'confirme_date', 'annee_debut' => 2019], contributionId: $contribution->id, quand: '2026-12-07 10:00:00', parAdmin: 'Valideur');

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.label', 'Dates corrigées (contribution validée)')
                ->where('journal.items.0.auteur', 'un contributeur anonyme')
        );
    }

    public function test_page_projet_affiche_l_historique_complet_du_projet(): void
    {
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');
        $autre = $this->projet('abc-autre', 'ABC autre');
        $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.fr', 'password' => 'x']);

        $contribution = Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'statut',
            'payload_json' => json_encode(['statut_suggere' => 'termine']),
            'statut' => 'validee',
        ]);

        $this->journalise('validee', ['etat' => 'confirme_termine'], contributionId: $contribution->id, quand: '2026-12-01 10:00:00');
        $this->journalise('verification', [
            'projet_id' => $projet->id,
            'nom' => $projet->nom,
            'etat' => 'confirme_date',
            'annee_debut' => 2019,
            'annee_fin' => 2023,
        ], quand: '2026-12-02 10:00:00');
        // Modification d'un autre projet : ne doit pas apparaître.
        $this->journalise('verification', [
            'projet_id' => $autre->id,
            'nom' => $autre->nom,
            'etat' => 'introuvable',
        ], quand: '2026-12-03 10:00:00');

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->has('projet.historique', 2)
                ->where('projet.historique.0.label', 'Dates confirmées')
                ->where('projet.historique.0.detail', '2019-2023')
                ->where('projet.historique.0.auteur', 'test')
                ->where('projet.historique.1.label', 'Statut corrigé (contribution validée)')
                ->where('projet.historique.1.auteur', 'Jane Doe')
        );
    }

    public function test_home_journal_nomme_un_projet_supprime_meme_sans_fiche(): void
    {
        $this->journalise('projet_supprime', null, [
            'projet_id' => 'abc-fantome',
            'nom' => 'ABC fantôme',
        ], quand: '2026-12-06 10:00:00');

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.label', 'Projet retiré de la carte')
                ->where('journal.items.0.projet_nom', 'ABC fantôme')
                ->where('journal.items.0.projet_slug', null)
        );
    }

    /**
     * Le journal public ne montre une correction de contributeur qu'une fois
     * validée par un admin ou un modérateur : une suggestion en attente n'y
     * figure jamais, ni sur l'accueil ni dans l'historique de la fiche.
     */
    public function test_une_suggestion_n_apparait_au_journal_qu_une_fois_validee(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $projet = $this->projet('abc-vannes', 'ABC de Vannes');

        $contributeur = User::factory()->create(['name' => 'Contributeur Y']);
        $contributeur->assignRole('contributeur');
        $moderateur = User::factory()->create(['name' => 'Modérateur X']);
        $moderateur->assignRole('moderateur');

        $this->actingAs($contributeur)->postJson('/api/contributions', [
            'projet_id' => $projet->id,
            'type' => 'date_debut',
            'annee_debut_suggeree' => 2019,
        ])->assertCreated();

        // Suggestion en attente : invisible du journal et de l'historique.
        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('journal.total', 0)->has('journal.items', 0)
        );
        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('projet.historique', 0)
        );

        // Validation par un modérateur : la correction entre au journal,
        // attribuée à son auteur.
        $contribution = Contribution::firstOrFail();
        $this->actingAs($moderateur)
            ->postJson("/api/admin/contributions/{$contribution->id}/valider")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('journal.items', 1)
                ->where('journal.items.0.label', 'Dates corrigées (contribution validée)')
                ->where('journal.items.0.auteur', $contributeur->name)
        );
        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('projet.historique', 1)
                ->where('projet.historique.0.auteur', $contributeur->name)
        );
    }
}
