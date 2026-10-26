<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1CommunesTest extends TestCase
{
    use RefreshDatabase;

    protected function projet(string $id, array $attrs = []): Projet
    {
        return Projet::create(array_merge([
            'id' => $id,
            'nom' => 'ABC '.$id,
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

    public function test_fiche_commune_et_projets_lies(): void
    {
        $vannes = $this->projet('abc-vannes', ['annee_debut' => 2010]);
        $second = $this->projet('abc-vannes-2');

        $this->commune($vannes);
        $this->commune($second, ['projet_id' => 'abc-vannes-2']);

        $response = $this->getJson('/api/v1/communes/56260');
        $response->assertOk();

        $this->assertSame('56260', $response->json('data.code'));
        $this->assertSame('Vannes', $response->json('data.libelle'));
        $this->assertSame(2, $response->json('data.n_projets'));
        $this->assertSame('Etalab-2.0', $response->json('data.licence'));

        $ids = array_column($response->json('data.projets'), 'projet_id');
        $this->assertEqualsCanonicalizing(['abc-vannes', 'abc-vannes-2'], $ids);

        // Provenance portée par chaque projet lié.
        $premier = collect($response->json('data.projets'))->firstWhere('projet_id', 'abc-vannes');
        $this->assertSame('Registre national des ABC — OFB / data.gouv.fr', $premier['provenance']['source_origine']);
        $this->assertSame('en_attente', $premier['statut_verification']);
    }

    public function test_fiche_commune_geojson(): void
    {
        $projet = $this->projet('abc-vannes');
        $this->commune($projet);

        $response = $this->getJson('/api/v1/communes/56260?format=geojson');
        $response->assertOk();

        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertSame('Point', $response->json('features.0.geometry.type'));
        $this->assertSame([2.76, 47.66], $response->json('features.0.geometry.coordinates'));
        $this->assertSame('abc-vannes', $response->json('features.0.properties.projet_id'));
        $this->assertSame('Etalab-2.0', $response->json('commune.licence'));
    }

    public function test_commune_inconnue_404(): void
    {
        $this->getJson('/api/v1/communes/99999')->assertStatus(404);
    }

    public function test_synthese_departement(): void
    {
        $projet = $this->projet('abc-vannes');
        $this->commune($projet);

        $response = $this->getJson('/api/v1/departements/56');
        $response->assertOk();

        $this->assertSame('56', $response->json('data.code'));
        $this->assertSame('Morbihan', $response->json('data.label'));
        $this->assertSame(1, $response->json('data.n_projets'));
        $this->assertSame('Etalab-2.0', $response->json('data.licence'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $response->json('data.derniere_mise_a_jour'));
        $this->assertSame('abc-vannes', $response->json('data.projets.0.projet_id'));
        $this->assertSame('Etalab-2.0', $response->json('data.projets.0.provenance.licence'));
    }

    public function test_departement_inconnu_404(): void
    {
        $this->getJson('/api/v1/departements/98')->assertStatus(404);
    }

    public function test_synthese_region_par_slug_code_et_libelle(): void
    {
        $projet = $this->projet('abc-vannes');
        $this->commune($projet);

        foreach (['bretagne', '53', 'Bretagne'] as $identifiant) {
            $response = $this->getJson('/api/v1/regions/'.$identifiant);
            $response->assertOk();

            $this->assertSame('Bretagne', $response->json('data.label'));
            $this->assertSame('bretagne', $response->json('data.slug'));
            $this->assertSame(1, $response->json('data.n_projets'));
            $this->assertSame('Etalab-2.0', $response->json('data.licence'));
        }
    }

    public function test_region_inconnue_404(): void
    {
        $this->getJson('/api/v1/regions/atlantide')->assertStatus(404);
    }
}
