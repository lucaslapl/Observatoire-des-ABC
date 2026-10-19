<?php

namespace Tests\Unit;

use App\Models\Commune;
use App\Models\Projet;
use App\Services\ExportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ExportServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_csv_export_contains_header_and_rows(): void
    {
        $projet = Projet::create([
            'id' => 'abc-export',
            'nom' => 'ABC Export',
            'structure_porteuse' => 'Mairie de Test',
            'annee_debut' => 2021,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '10001',
            'libelle_geographique' => 'Test',
            'departement' => '01',
            'libelle_departement' => 'Ain',
            'region' => 'Auvergne-Rhône-Alpes',
            'lon' => 2.35,
            'lat' => 48.85,
        ]);

        $csv = app(ExportService::class)->export('csv');

        $lines = explode("\n", trim($csv));
        $this->assertSame(
            'id;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;statut;categorie;note;nb_communes;departement;region;source;ami_ofb;url_page',
            $lines[0],
        );
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('abc-export;ABC Export;Mairie de Test', $lines[1]);
        $this->assertStringContainsString('En cours', $lines[1]);
        $this->assertStringContainsString(';1;Ain;Auvergne-Rhône-Alpes', $lines[1]);
    }

    public function test_geojson_export_contains_features(): void
    {
        $projet = Projet::create([
            'id' => 'abc-export-json',
            'nom' => 'ABC Export JSON',
            'statut' => 'termine',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '10002',
            'libelle_geographique' => 'Test',
            'lon' => 2.35,
            'lat' => 48.85,
        ]);

        $geojson = json_decode(app(ExportService::class)->export('geojson'), true);

        $this->assertSame('FeatureCollection', $geojson['type']);
        $this->assertCount(1, $geojson['features']);
        $this->assertSame('abc-export-json', $geojson['features'][0]['properties']['projet_id']);
    }

    public function test_unknown_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ExportService::class)->export('xml');
    }
}
