<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * abc:sync-confirmed-dates : matérialise les lignes « Dates confirmées : … »
 * des notes de vérification dans projets.annee_debut / annee_fin.
 *
 * Les vérifications héritées de l'ère Node portaient la date confirmée
 * uniquement dans la note : les pages de liste (département, commune, fiche,
 * carte) affichaient alors la date source, voire aucune date.
 */
class SyncConfirmedDatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_materialise_une_date_confirmee_absente_des_colonnes(): void
    {
        // Vitré : la date confirmée 2026 ne vivait que dans la note.
        Projet::create([
            'id' => 'atlas-vitre-2025',
            'nom' => 'Atlas de la Biodiversité Communale',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
        ]);
        Verification::create([
            'projet_id' => 'atlas-vitre-2025',
            'etat' => 'confirme_date',
            'note' => 'Dates confirmées : 2026 — Source : Ville de Vitré',
            'verifie_le' => now(),
            'verifie_par' => 'admin',
        ]);

        $this->artisan('abc:sync-confirmed-dates')->assertSuccessful();

        $this->assertDatabaseHas('projets', [
            'id' => 'atlas-vitre-2025',
            'annee_debut' => 2026,
            'annee_fin' => null,
        ]);
        $this->assertDatabaseHas('audit_log', ['action' => 'sync_dates']);
    }

    public function test_une_date_humaine_prime_sur_l_annee_source(): void
    {
        // Brandivy : la source dit 2024, l'humain a confirmé 2025–2027.
        Projet::create([
            'id' => 'abc-brandivy-2024',
            'nom' => 'ABC de Brandivy',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ]);
        Verification::create([
            'projet_id' => 'abc-brandivy-2024',
            'etat' => 'confirme_date',
            'note' => 'Dates confirmées : 2025–2027 — Source : https://www.brandivy.fr/biodiversite.html',
            'verifie_le' => now(),
            'verifie_par' => 'admin',
        ]);

        $this->artisan('abc:sync-confirmed-dates')->assertSuccessful();

        $this->assertDatabaseHas('projets', [
            'id' => 'abc-brandivy-2024',
            'annee_debut' => 2025,
            'annee_fin' => 2027,
        ]);
    }

    public function test_est_idempotent_et_respecte_dry_run(): void
    {
        Projet::create([
            'id' => 'abc-seche',
            'nom' => 'ABC séché',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ]);
        Verification::create([
            'projet_id' => 'abc-seche',
            'etat' => 'confirme_date',
            'note' => 'Dates confirmées : 2025',
        ]);

        // dry-run : aucun changement écrit, aucun audit.
        $this->artisan('abc:sync-confirmed-dates', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('projets', ['id' => 'abc-seche', 'annee_debut' => 2024]);
        $this->assertSame(0, AuditLog::where('action', 'sync_dates')->count());

        $this->artisan('abc:sync-confirmed-dates')->assertSuccessful();
        $this->assertDatabaseHas('projets', ['id' => 'abc-seche', 'annee_debut' => 2025]);

        // Second passage : déjà conforme, aucune retouche ni nouvel audit.
        $this->artisan('abc:sync-confirmed-dates')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('action', 'sync_dates')->count());
    }

    public function test_ignore_les_notes_sans_dates_exploitable(): void
    {
        Projet::create([
            'id' => 'abc-intact',
            'nom' => 'ABC intact',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2023,
        ]);
        Verification::create([
            'projet_id' => 'abc-intact',
            'etat' => 'confirme_en_cours',
            'note' => 'Site de la ville : démarrage constaté',
        ]);

        $this->artisan('abc:sync-confirmed-dates')->assertSuccessful();

        $this->assertDatabaseHas('projets', ['id' => 'abc-intact', 'annee_debut' => 2023]);
        $this->assertSame(0, AuditLog::where('action', 'sync_dates')->count());
    }
}
