<?php

namespace Tests\Unit;

use App\Services\StatusService;
use Tests\TestCase;

class StatusServiceTest extends TestCase
{
    private StatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatusService;
    }

    public function test_avancement_to_statut_mapping(): void
    {
        $this->assertSame('en_cours', $this->service->avancementToStatut('En cours de réalisation'));
        $this->assertSame('termine', $this->service->avancementToStatut('Fini'));
        $this->assertSame('a_venir', $this->service->avancementToStatut('En phase de lancement'));
        $this->assertSame('a_venir', $this->service->avancementToStatut('Non commencé'));
        $this->assertSame('inconnu', $this->service->avancementToStatut('Inconnu'));
        $this->assertSame('inconnu', $this->service->avancementToStatut(''));
        $this->assertSame('inconnu', $this->service->avancementToStatut(null));
        $this->assertSame('inconnu', $this->service->avancementToStatut('valeur inattendue'));
    }

    public function test_statut_label(): void
    {
        $this->assertSame('Va débuter', $this->service->statutLabel('a_venir'));
        $this->assertSame('En cours', $this->service->statutLabel('en_cours'));
        $this->assertSame('Terminé', $this->service->statutLabel('termine'));
        $this->assertSame('Statut inconnu', $this->service->statutLabel('inconnu'));
    }

    public function test_est_potentiellement_termine_apres_3_ans(): void
    {
        config(['abc.duree_abc_ans' => 3]);
        $this->assertTrue($this->service->estPotentiellementTermine('en_cours', 2021, 2025));
        $this->assertFalse($this->service->estPotentiellementTermine('en_cours', 2023, 2025));
        $this->assertFalse($this->service->estPotentiellementTermine('en_cours', 2023, 2025));
        $this->assertFalse($this->service->estPotentiellementTermine('termine', 2020, 2025));
        $this->assertFalse($this->service->estPotentiellementTermine('en_cours', null, 2025));
    }

    public function test_resolve_categorie(): void
    {
        $this->assertSame('va_debuter', $this->service->resolveCategorie('a_venir', [], false));
        $this->assertSame('va_debuter', $this->service->resolveCategorie('a_venir', [], true));
        $this->assertSame('en_cours', $this->service->resolveCategorie('en_cours', [], false));
        $this->assertSame('termine', $this->service->resolveCategorie('termine', [], false));
        $this->assertSame('inconnu', $this->service->resolveCategorie('inconnu', [], false));
        $this->assertSame('termine', $this->service->resolveCategorie('en_cours', ['Fini'], false));
    }

    public function test_statut_depuis_verification(): void
    {
        $this->assertSame('termine', $this->service->statutDepuisVerification('confirme_termine'));
        $this->assertSame('en_cours', $this->service->statutDepuisVerification('confirme_en_cours'));
        $this->assertSame('a_venir', $this->service->statutDepuisVerification('toujours_a_venir'));
        $this->assertNull($this->service->statutDepuisVerification('a_verifier'));
        $this->assertNull($this->service->statutDepuisVerification('introuvable'));
        $this->assertNull($this->service->statutDepuisVerification(null));
    }

    public function test_verification_etat_pour_statut(): void
    {
        $this->assertSame('confirme_termine', $this->service->verificationEtatPourStatut('termine'));
        $this->assertSame('confirme_en_cours', $this->service->verificationEtatPourStatut('en_cours'));
        $this->assertSame('toujours_a_venir', $this->service->verificationEtatPourStatut('va_debuter'));
        $this->assertNull($this->service->verificationEtatPourStatut('autre'));
    }
}
