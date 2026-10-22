<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Financement;
use App\Models\Projet;
use App\Services\CollectService;
use App\Services\ProjectIdService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4 de l'audit du 2026-10-21 : fiabilisation du pipeline.
 *
 * 1. un collect complet rejoué DEUX FOIS sur des copies locales (fixtures)
 *    ne crée aucun doublon ni aucune différence ;
 * 2. un CSV piégé contenant un doublon de nom normalisé fait échouer le
 *    collect (alerte bloquante, journalisée) au lieu de l'insérer en silence ;
 * 3. plus de 5 % de créations nettes déclenche la même alerte.
 */
class CollectIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir().'/abc-collect-int-'.uniqid();
        mkdir($this->cacheDir, 0777, true);
        config(['abc.cache_dir' => $this->cacheDir, 'abc.auto_resolve_sources' => false]);
        // Aucun réseau réel : géocodage (et résolution) interceptés.
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir.'/*') ?: []);
        rmdir($this->cacheDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures : copies locales des CSV des 3 sources
    // ------------------------------------------------------------------

    private function ecrireSources(string $registreExtra = ''): void
    {
        $registre =
            "index;code_geographique;libelle_geographique;epci;libelle_epci;departement;libelle_departement;region;ept;libelle_petr;code_pnr;libelle_pnr;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;avancement\n".
            "1;11001;Village-Un;;CC Village;11;Aude;11;;;110;PNR Test;ABC de Village-Un;Village-Un;Commune;2020;Fini\n".
            "2;11002;Village-Un-2;;CC Village;11;Aude;11;;;110;PNR Test;ABC de Village-Un;Village-Un;Commune;2020;Fini\n".
            "3;12001;Village-Deux;;CC Village;12;Aveyron;12;;;;;ABC de Village-Deux;Village-Deux;Commune;2021;En cours de réalisation\n".
            "4;13001;Bourg-Edition;;CC Bourg;13;Bouches-du-Rhône;13;;;;;ABC de Bourg (édition n°1);Bourg;Commune;2010;Fini\n".
            $registreExtra;

        file_put_contents($this->cacheDir.'/atlas-biodiversite.csv', $registre);

        // Wayback : un projet déjà couvert par le registre (snapshot
        // historique) + un projet connu des seules archives 2022.
        file_put_contents($this->cacheDir.'/abcexport',
            "identifiant_technique,nom,structure_porteuse,type_de_structure_porteuse,commune,code_commune,annee_debut,ami_ofb,avancement,ressource_documentaire\n".
            "c1,\"ABC de Village-Un\",Village-Un,Collectivité,Village-Un,11001,2020,Vrai,En cours de réalisation,https://ex.fr/1\n".
            "c2,\"ABC Historique\",Mairie d'Antan,Collectivité,Antan,14001,2019,Non,Inconnu,https://ex.fr/2\n"
        );

        // Fonds vert 2024/2025 : aucune colonne commune ; une ligne ABC
        // rattachable à un projet connu par nom normalisé (financement),
        // une nouvelle (worklist).
        $fvHeader = "numero_ej,nom_du_projet,nom_fournisseur,montant_engage,numero_ds,source\n";
        file_put_contents($this->cacheDir.'/fonds-vert-p113-2024-export.csv',
            $fvHeader."1,\"ABC de Village-Deux\",\"Village-Deux\",80000.00,11300001,FV\n"
        );
        file_put_contents($this->cacheDir.'/fonds-vert-p113-2025-export.csv.csv',
            $fvHeader."2,\"ABC du Nouveau Financement\",\"COMMUNE DE NOUVEAU\",50000.00,11300002,FV\n"
        );
    }

    private function collecter(): array
    {
        return app(CollectService::class)->collectAll();
    }

    private function etat(): array
    {
        return [
            'projets' => Projet::orderBy('id')->get(['id', 'nom', 'statut', 'annee_debut'])->map(fn ($p) => $p->getAttributes())->all(),
            'communes' => \DB::table('communes')->orderBy('projet_id')->orderBy('code_geographique')->get(['projet_id', 'code_geographique'])->map(fn ($c) => (array) $c)->all(),
            'snapshots' => \DB::table('snapshots')->count(),
            'financements' => Financement::count(),
            'verifications' => \DB::table('verifications')->count(),
            'audit' => AuditLog::count(),
        ];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_collect_rejoue_deux_fois_sans_doublon_ni_difference(): void
    {
        $this->ecrireSources();

        $this->collecter();
        $premier = $this->etat();

        $this->collecter();
        $second = $this->etat();

        $this->assertSame($premier, $second, 'le second collect ne doit rien changer');

        // Aucun doublon de nom normalisé créé.
        $ids = app(ProjectIdService::class);
        $noms = Projet::pluck('nom', 'id')->map(fn ($n) => $ids->slug((string) $n))->all();
        $this->assertSame(count($noms), count(array_unique($noms)), 'aucun nom normalisé dupliqué');

        // Le financement FV est rattaché au projet connu, aucun projet FV créé.
        $this->assertSame(1, Financement::count());
        $this->assertSame('abc-de-village-deux-village-deux-2021', Financement::first()->project_id);
        $this->assertSame(4, Projet::count()); // 3 registre + 1 wayback inconnu

        // Le candidat FV nouveau est en worklist, journalisé.
        $this->assertSame(1, AuditLog::where('action', 'worklist_fondsvert')->count());

        // Aucune alerte.
        $this->assertSame(0, AuditLog::where('action', 'alerte_collect')->count());
    }

    public function test_csv_piege_avec_doublon_de_nom_normalise_fait_echouer_le_collect(): void
    {
        $this->ecrireSources();
        $this->collecter(); // état initial : 4 projets (3 registre + 1 wayback)
        $etatAvant = $this->etat();

        // CSV piégé : le registre ré-apparaît avec une ligne supplémentaire
        // portant le même nom qu'un projet existant (année différente → id
        // différent, donc nouvelle création silencieuse sans le garde-fou).
        $registrePiege =
            "index;code_geographique;libelle_geographique;epci;libelle_epci;departement;libelle_departement;region;ept;libelle_petr;code_pnr;libelle_pnr;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;avancement\n".
            "1;11001;Village-Un;;CC Village;11;Aude;11;;;110;PNR Test;ABC de Village-Un;Village-Un;Commune;2020;Fini\n".
            "2;11002;Village-Un-2;;CC Village;11;Aude;11;;;110;PNR Test;ABC de Village-Un;Village-Un;Commune;2020;Fini\n".
            "3;12001;Village-Deux;;CC Village;12;Aveyron;12;;;;;ABC de Village-Deux;Village-Deux;Commune;2021;En cours de réalisation\n".
            "4;13001;Bourg-Edition;;CC Bourg;13;Bouches-du-Rhône;13;;;;;ABC de Bourg (édition n°1);Bourg;Commune;2010;Fini\n".
            "5;11003;Village-Un-3;;CC Village;11;Aude;11;;;;;ABC de Village-Un;Village-Un;Commune;2024;En phase de lancement\n";
        file_put_contents($this->cacheDir.'/atlas-biodiversite.csv', $registrePiege);

        try {
            $this->collecter();
            $this->fail('Le collect devait être bloqué par le contrôle post-collect.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('partage son nom normalisé', $e->getMessage());
        }

        // Alerte journalisée, avec les projets en cause.
        $this->assertSame(1, AuditLog::where('action', 'alerte_collect')->count());
        $payload = json_decode(AuditLog::where('action', 'alerte_collect')->first()->apres, true);
        $this->assertSame(['abc-de-village-un-village-un-2024'], $payload['crees']);

        // Le collect est interrompu AVANT toute suppression ciblée : les
        // projets connus sont toujours là, le doublon incriminé reste en
        // base pour examen par l'opérateur.
        $this->assertSame(5, Projet::count());
        $this->assertDatabaseHas('projets', ['id' => 'abc-de-village-un-village-un-2020']);
    }

    public function test_plus_de_5_pourcent_de_creations_netttes_bloque_le_collect(): void
    {
        $this->ecrireSources();
        $this->collecter(); // 4 projets

        // Un registre gonflé : 6 nouveaux projets inconnus (noms uniques,
        // pas de collision) = 120 % de créations, loin au-dessus des 5 %.
        $lignes = '';
        foreach (range(1, 6) as $i) {
            $lignes .= "{$i};2{$i}001;Commune-{$i};;CC Neuve;2{$i};Département {$i};2{$i};;;;;ABC de Commune-{$i};Commune-{$i};Commune;2024;En phase de lancement\n";
        }
        $this->ecrireSources($lignes);

        try {
            $this->collecter();
            $this->fail('Le collect devait être bloqué par le seuil de 5 % de créations.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('créations nettes', $e->getMessage());
        }

        $this->assertSame(1, AuditLog::where('action', 'alerte_collect')->count());
        $payload = json_decode(AuditLog::where('action', 'alerte_collect')->first()->apres, true);
        $this->assertCount(6, $payload['crees']);
    }
}
