<?php

namespace Tests\Feature;

use App\Http\Middleware\CacheApiResponse;
use App\Models\Commune;
use App\Models\Projet;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HEAD ne doit jamais empoisonner le cache applicatif : Symfony vide le
 * corps des réponses HEAD dans Router::toResponse (Response::prepare,
 * RFC 2616 § 14.13) avant que CacheApiResponse ne le voie. Sans garde,
 * une requête HEAD mettait en cache un corps vide servi ensuite aux GET.
 */
class ApiV1HeadCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function projetAvecCommune(): Projet
    {
        $projet = Projet::create([
            'id' => 'abc-head',
            'nom' => 'ABC Head',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56260',
            'libelle_geographique' => 'Vannes',
            'anomalie' => false,
        ]);

        return $projet;
    }

    public function test_un_head_nempoisonne_pas_le_get_suivant(): void
    {
        $this->projetAvecCommune();

        // HEAD : traversé par l'application, corps vidé par Symfony…
        $head = $this->head('/api/v1/projets');
        $head->assertOk();

        // …mais ni lu ni écrit dans le cache : l'entrée du GET n'existe pas.
        $this->assertFalse(Cache::has(ApiCache::key(Request::create('http://localhost/api/v1/projets', 'GET'))));

        // Le GET suivant renvoie bien les données, pas la réponse vide du HEAD.
        $get = $this->getJson('/api/v1/projets');
        $get->assertOk();
        $this->assertNotEmpty($get->getContent());
        $this->assertSame('abc-head', $get->json('data.0.id'));
        $this->assertNotSame(
            hash('sha256', ''),
            trim((string) $get->getEtag(), '"'),
            'L\'ETag du GET ne doit pas être celui de la chaîne vide.'
        );
    }

    public function test_get_avec_if_none_match_renvoie_304_sans_empoisonner(): void
    {
        $this->projetAvecCommune();

        $etag = $this->getJson('/api/v1/projets')->getEtag();

        $this->getJson('/api/v1/projets', ['If-None-Match' => $etag])
            ->assertStatus(304);

        // Le 304 n'a rien stocké : le GET suivant est servi complet.
        $get = $this->getJson('/api/v1/projets');
        $get->assertOk();
        $this->assertSame('abc-head', $get->json('data.0.id'));
        $this->assertSame($etag, $get->getEtag());
    }

    public function test_deux_get_identiques_meme_etag_et_second_servi_du_cache(): void
    {
        $this->projetAvecCommune();

        DB::enableQueryLog();
        $premier = $this->getJson('/api/v1/projets');
        $premier->assertOk();
        $apresPremier = count(DB::getQueryLog());

        $second = $this->getJson('/api/v1/projets');
        $second->assertOk();

        // Même ETag, corps identique, zéro requête SQL supplémentaire.
        $this->assertSame($premier->getEtag(), $second->getEtag());
        $this->assertSame($premier->getContent(), $second->getContent());
        $this->assertSame($apresPremier, count(DB::getQueryLog()));
    }

    public function test_une_reponse_au_corps_vide_nest_jamais_stockee(): void
    {
        $executions = 0;
        Route::middleware(CacheApiResponse::class)->get('_test/corps-vide', function () use (&$executions) {
            $executions++;

            return response('', 200, ['Content-Type' => 'application/json']);
        });

        $this->get('_test/corps-vide')->assertOk();
        $this->get('_test/corps-vide')->assertOk();

        // Corps vide = jamais mis en cache : la route s'exécute à chaque fois.
        $this->assertSame(2, $executions);
    }
}
