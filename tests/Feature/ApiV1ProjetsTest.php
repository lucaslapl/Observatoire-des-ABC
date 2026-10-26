<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1ProjetsTest extends TestCase
{
    use RefreshDatabase;

    protected function projet(string $id, array $attrs = []): Projet
    {
        return Projet::create(array_merge([
            'id' => $id,
            'nom' => 'ABC de Test '.$id,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ], $attrs));
    }

    protected function commune(Projet $projet, array $attrs = []): Commune
    {
        return Commune::create(array_merge([
            'projet_id' => $projet->id,
            'code_geographique' => '56260',
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => 'Bretagne',
            'anomalie' => false,
            'lon' => 2.76,
            'lat' => 47.66,
        ], $attrs));
    }

    public function test_la_liste_est_paginee_par_curseur(): void
    {
        $this->projet('abc-a');
        $this->projet('abc-b');
        $this->projet('abc-c');

        $response = $this->getJson('/api/v1/projets?per_page=2');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(2, $response->json('meta.per_page'));
        $this->assertNotNull($response->json('meta.next_cursor'));
        $this->assertNull($response->json('meta.prev_cursor'));

        $cursor = $response->json('meta.next_cursor');
        $page2 = $this->getJson('/api/v1/projets?per_page=2&cursor='.urlencode($cursor));
        $page2->assertOk();

        $idsPage1 = array_column($data, 'id');
        $idsPage2 = array_column($page2->json('data'), 'id');

        $this->assertCount(1, $idsPage2);
        $this->assertEmpty(array_intersect($idsPage1, $idsPage2));
    }

    public function test_chaque_enregistrement_expose_sa_provenance(): void
    {
        $projet = $this->projet('abc-provenance', ['source' => 'fondsvert-p113-2024']);
        $this->commune($projet, ['code_geographique' => '35238', 'libelle_geographique' => 'Rennes']);

        $user = User::factory()->create(['name' => 'Jeanne Dupont', 'email' => 'jeanne@example.org']);
        Contribution::create([
            'projet_id' => $projet->id,
            'user_id' => $user->id,
            'type' => 'statut',
            'payload_json' => ['statut' => 'termine'],
            'ip' => '203.0.113.9',
            'statut' => 'validee',
        ]);

        $response = $this->getJson('/api/v1/projets');
        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', 'abc-provenance');

        $this->assertNotNull($item);
        $this->assertSame('Fonds vert 2024', $item['provenance']['source_origine']);
        $this->assertSame('Etalab-2.0', $item['provenance']['licence']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $item['provenance']['derniere_mise_a_jour']);
        $this->assertSame(1, $item['provenance']['enrichi_par_contributeurs']);
        $this->assertSame('en_attente', $item['statut_verification']);
        $this->assertArrayNotHasKey('ip', $item);
    }

    public function test_le_statut_verifie_prime_sur_le_filtre(): void
    {
        $projet = $this->projet('abc-verifie', ['statut' => 'en_cours']);
        $this->commune($projet);
        Verification::create([
            'projet_id' => $projet->id,
            'etat' => 'confirme_termine',
            'verifie_par' => 'Un Admin',
        ]);

        // Statut calculé « en cours » mais verdict vérifié « terminé » :
        // le filtre statut porte sur le statut affiché, comme sur le site.
        $response = $this->getJson('/api/v1/projets?statut=termine');
        $response->assertOk();
        $this->assertSame('abc-verifie', $response->json('data.0.id'));
        $this->assertSame('verifie', $response->json('data.0.statut_verification'));

        $absent = $this->getJson('/api/v1/projets?statut=en_cours');
        $absent->assertOk();
        $this->assertCount(0, $absent->json('data'));
    }

    public function test_filtres_annee_code_insee_departement_region_porteur(): void
    {
        $vannes = $this->projet('abc-vannes', [
            'structure_porteuse' => 'Commune de Vannes',
            'annee_debut' => 2010,
        ]);
        $this->commune($vannes);

        $rennes = $this->projet('abc-rennes', ['annee_debut' => 2021]);
        $this->commune($rennes, [
            'code_geographique' => '35238',
            'libelle_geographique' => 'Rennes',
            'departement' => '35',
            'libelle_departement' => 'Ille-et-Vilaine',
        ]);

        $this->assertSame('abc-vannes', $this->getJson('/api/v1/projets?annee=2010')->json('data.0.id'));
        $this->assertSame('abc-rennes', $this->getJson('/api/v1/projets?code_insee=35238')->json('data.0.id'));
        $this->assertSame('abc-rennes', $this->getJson('/api/v1/projets?departement=35')->json('data.0.id'));
        $this->assertSame('abc-vannes', $this->getJson('/api/v1/projets?departement=56')->json('data.0.id'));

        // Les deux projets sont en Bretagne : le filtre région les ramène
        // tous les deux, quel que soit l'identifiant utilisé (slug, code
        // INSEE, libellé).
        $ids = fn ($url) => array_column($this->getJson($url)->json('data'), 'id');
        $this->assertEqualsCanonicalizing(['abc-rennes', 'abc-vannes'], $ids('/api/v1/projets?region=bretagne'));
        $this->assertEqualsCanonicalizing(['abc-rennes', 'abc-vannes'], $ids('/api/v1/projets?region=53'));
        $this->assertEqualsCanonicalizing(['abc-rennes', 'abc-vannes'], $ids('/api/v1/projets?region=Bretagne'));
        $this->assertSame('abc-vannes', $this->getJson('/api/v1/projets?porteur=Vannes')->json('data.0.id'));

        // Région inconnue des données : aucun résultat, pas tout renvoyer.
        $this->assertCount(0, $this->getJson('/api/v1/projets?region=atlantide')->json('data'));
    }

    public function test_filtre_bbox(): void
    {
        $vannes = $this->projet('abc-vannes');
        $this->commune($vannes); // lon 2.76, lat 47.66

        $nice = $this->projet('abc-nice');
        $this->commune($nice, [
            'code_geographique' => '06088',
            'libelle_geographique' => 'Nice',
            'departement' => '06',
            'libelle_departement' => 'Alpes-Maritimes',
            'region' => "Provence-Alpes-Côte d'Azur",
            'lon' => 7.26,
            'lat' => 43.70,
        ]);

        $response = $this->getJson('/api/v1/projets?bbox=1,47,4,48');
        $response->assertOk();

        $this->assertSame(['abc-vannes'], array_column($response->json('data'), 'id'));
    }

    public function test_format_geojson_par_parametre_et_par_accept(): void
    {
        $projet = $this->projet('abc-geojson');
        $this->commune($projet);

        $param = $this->getJson('/api/v1/projets?format=geojson&per_page=10');
        $param->assertOk();
        $this->assertSame('application/geo+json', $param->headers->get('Content-Type'));
        $this->assertSame('FeatureCollection', $param->json('type'));
        $this->assertSame('Feature', $param->json('features.0.type'));
        $this->assertSame('Point', $param->json('features.0.geometry.type'));
        $this->assertSame([2.76, 47.66], $param->json('features.0.geometry.coordinates'));
        $this->assertSame('abc-geojson', $param->json('features.0.properties.id'));
        $this->assertSame('Etalab-2.0', $param->json('features.0.properties.provenance.licence'));

        $accept = $this->getJson('/api/v1/projets', ['Accept' => 'application/geo+json']);
        $accept->assertOk();
        $this->assertSame('FeatureCollection', $accept->json('type'));
    }

    public function test_fiche_projet_detaillee(): void
    {
        $projet = $this->projet('abc-fiche', ['source' => 'wayback']);
        $this->commune($projet);

        Verification::create([
            'projet_id' => $projet->id,
            'etat' => 'confirme_en_cours',
            'note' => 'Vérifié sur le site de la mairie.',
            'verifie_par' => 'Un Admin',
        ]);

        $response = $this->getJson('/api/v1/projets/abc-fiche');
        $response->assertOk();

        $this->assertSame('abc-fiche', $response->json('data.id'));
        $this->assertSame('verifie', $response->json('data.statut_verification'));
        $this->assertSame('en_cours', $response->json('data.statut'));
        $this->assertSame('Registre national des ABC (archives 2022) — OFB / Wayback Machine', $response->json('data.provenance.source_origine'));
        $this->assertSame('Etalab-2.0', $response->json('data.provenance.licence'));

        // Aucune donnée d'opérateur ne fuit (verifie_par non exposé).
        $this->assertStringNotContainsString('Un Admin', $response->getContent());
        $this->assertArrayNotHasKey('verifie_par', $response->json('data.verification'));
    }

    public function test_fiche_projet_inconnue_404(): void
    {
        $this->getJson('/api/v1/projets/inconnu')->assertStatus(404);
    }

    public function test_validation_renvoie_422_en_francais(): void
    {
        $response = $this->getJson('/api/v1/projets?statut=bogus');
        $response->assertStatus(422);
        $this->assertStringContainsString('a_venir', $response->json('errors.statut.0'));
    }
}
