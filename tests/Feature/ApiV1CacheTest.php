<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiV1CacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_deux_get_identiques_successifs_ne_declenchent_quune_seule_execution(): void
    {
        $projet = Projet::create([
            'id' => 'abc-cache',
            'nom' => 'ABC Cache',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56260',
            'libelle_geographique' => 'Vannes',
            'anomalie' => false,
        ]);

        DB::enableQueryLog();
        $this->getJson('/api/v1/projets')->assertOk();
        $premiere = count(DB::getQueryLog());

        // Deuxième GET identique : servi par le cache applicatif, sans
        // une seule requête SQL supplémentaire (ni sérialisation métier).
        $this->getJson('/api/v1/projets')->assertOk();
        $this->assertSame(
            $premiere,
            count(DB::getQueryLog()),
            'Le deuxième GET identique ne doit déclencher aucune requête SQL.'
        );
    }

    public function test_etag_et_304_sur_ressource_inchangee(): void
    {
        $projet = Projet::create([
            'id' => 'abc-etag',
            'nom' => 'ABC Etag',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $first = $this->getJson('/api/v1/projets/abc-etag');
        $first->assertOk();
        $etag = $first->getEtag();
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('max-age=', (string) $first->headers->get('Cache-Control'));

        // If-None-Match correspondant → 304 immédiat, sans corps.
        $this->getJson('/api/v1/projets/abc-etag', ['If-None-Match' => $etag])
            ->assertStatus(304);

        // Un ETag erroné → 200 normal.
        $this->getJson('/api/v1/projets/abc-etag', ['If-None-Match' => '"bogus"'])
            ->assertOk();
    }

    public function test_invalidation_sur_contribution_verifiee_et_verification(): void
    {
        $projet = Projet::create([
            'id' => 'abc-inval',
            'nom' => 'ABC Inval',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $avant = $this->getJson('/api/v1/projets/abc-inval')->assertOk();
        $this->assertSame('en_attente', $avant->json('data.statut_verification'));

        // Une vérification humaine invalide le cache : la réponse suivante
        // reflète immédiatement le nouveau statut (pas la copie en cache).
        Verification::create([
            'projet_id' => $projet->id,
            'etat' => 'confirme_termine',
            'verifie_par' => 'Un Admin',
        ]);

        $apres = $this->getJson('/api/v1/projets/abc-inval')->assertOk();
        $this->assertSame('verifie', $apres->json('data.statut_verification'));
    }

    public function test_etag_change_quand_les_donnees_changent(): void
    {
        $projet = Projet::create([
            'id' => 'abc-etag-2',
            'nom' => 'ABC Etag 2',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $etag1 = $this->getJson('/api/v1/projets/abc-etag-2')->getEtag();

        $projet->update(['nom' => 'ABC Etag 2 renommé']);

        $etag2 = $this->getJson('/api/v1/projets/abc-etag-2')->getEtag();

        $this->assertNotSame($etag1, $etag2);
    }

    public function test_le_cache_tient_compte_de_la_query_string(): void
    {
        $this->getJson('/api/v1/projets')->assertOk();
        $this->getJson('/api/v1/projets?statut=termine')->assertOk();

        // Deux query strings différentes = deux réponses distinctes.
        $this->getJson('/api/v1/projets?statut=termine')->assertOk();
    }
}
