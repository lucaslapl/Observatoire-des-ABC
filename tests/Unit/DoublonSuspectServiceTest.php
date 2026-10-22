<?php

namespace Tests\Unit;

use App\Models\Projet;
use App\Models\Verification;
use App\Services\DoublonSuspectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signalement d'homonymies (même nom normalisé, projets distincts) : le
 * bouton « Fusionner ce doublon » de /verify n'apparaît que si une note
 * automatique « Doublon suspect » existe — le Fonds vert seul ne suffit
 * pas, deux éditions d'un même ABC (wayback / data.gouv) ne passent
 * jamais par une ligne de financement.
 */
class DoublonSuspectServiceTest extends TestCase
{
    use RefreshDatabase;

    private DoublonSuspectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DoublonSuspectService::class);
    }

    public function test_signale_les_homonymes_avec_une_note_auto_fusionnable(): void
    {
        // Cas réel : édition wayback (2022, sans année) + édition data.gouv
        // du même ABC — la fusion faite en admin avait disparu avec la
        // réimport, plus aucune note ne proposait de la refaire.
        $w = Projet::create(['id' => 'abc-paris-vallee-marne-ca-paris-vallee-marne', 'nom' => 'ABC Paris Vallée de la Marne', 'statut' => 'a_venir', 'source' => 'wayback']);
        $d = Projet::create(['id' => 'abc-paris-vallee-marne-ca-paris-vallee-marne-2022', 'nom' => 'ABC Paris vallée de la Marne', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        Verification::create(['projet_id' => $d->id, 'etat' => 'confirme_en_cours']);

        $signales = $this->service->signalerHomonymes();

        $this->assertSame(2, $signales);
        $verifW = Verification::find($w->id);
        $this->assertSame('a_verifier', $verifW->etat);
        $this->assertStringContainsString('Doublon suspect (homonymie)', (string) $verifW->note_auto);
        $this->assertStringContainsString('« ABC Paris Vallée de la Marne »', (string) $verifW->note_auto);
        $this->assertStringContainsString($w->id, (string) $verifW->note_auto);
        $this->assertStringContainsString($d->id, (string) $verifW->note_auto);

        // Le verdict humain existant est préservé, la ligne s'y ajoute.
        $verifD = Verification::find($d->id);
        $this->assertSame('confirme_en_cours', $verifD->etat);
        $this->assertStringContainsString('Doublon suspect (homonymie)', (string) $verifD->note_auto);
        $this->assertNull($verifD->note);
    }

    public function test_signaler_est_idempotent_et_mets_a_jour_le_groupe(): void
    {
        $a = Projet::create(['id' => 'abc-d-ambigu-a', 'nom' => "ABC d'Ambigu", 'statut' => 'termine', 'source' => 'data.gouv']);
        $b = Projet::create(['id' => 'abc-d-ambigu-b', 'nom' => "ABC d'ambigu", 'statut' => 'en_cours', 'source' => 'data.gouv']);
        $this->service->signalerHomonymes();

        $this->service->signalerHomonymes();
        $note = (string) Verification::find($a->id)->note_auto;
        $this->assertSame(1, substr_count($note, 'Doublon suspect (homonymie)'));

        // Le doublon fusionné disparaît : la ligne restante cite un nom qui
        // ne correspond plus qu'à un projet — nettoyer() la retire.
        $b->delete();
        $this->service->signalerHomonymes();
        $noteRestante = (string) Verification::find($a->id)->note_auto;
        $this->assertSame(1, substr_count($noteRestante, 'Doublon suspect (homonymie)'));
        $this->assertNull($this->service->nettoyer($noteRestante));
    }

    public function test_les_noms_generiques_ne_sont_pas_signales(): void
    {
        Projet::create(['id' => 'atlas-alba', 'nom' => "Réalisation d'un atlas de la biodiversité communale", 'statut' => 'en_cours', 'source' => 'fondsvert-p113-2024']);
        Projet::create(['id' => 'atlas-maule', 'nom' => "Réalisation d'un Atlas de la biodiversité communale", 'statut' => 'en_cours', 'source' => 'fondsvert-p113-2025']);
        Projet::create(['id' => 'atlas-titre', 'nom' => 'Atlas de la biodiversité communale', 'statut' => 'en_cours', 'source' => 'data.gouv']);
        Projet::create(['id' => 'atlas-titre-2', 'nom' => 'Atlas de la biodiversité communale', 'statut' => 'en_cours', 'source' => 'data.gouv']);

        $this->assertSame(0, $this->service->signalerHomonymes());
        $this->assertSame(0, Verification::count());
    }
}
