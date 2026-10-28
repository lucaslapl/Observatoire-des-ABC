<?php

namespace Tests\Feature;

use App\Models\InpnImport;
use App\Services\InpnImportService;
use App\Services\SqlDumpService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Import des référentiels INPN (abc:import-inpn) : filtres (FG_SUPP,
 * statut, espèces confidentielles), normalisation des codes communes,
 * conversion cp1252 → UTF-8 des GPKG, jointure spatiale esquivée sans
 * PostGIS, et dump portable abc:export-inpn. Les fixtures sont des
 * extraits minuscules reconstruits dans un dossier temporaire.
 */
class InpnImportTest extends TestCase
{
    use DatabaseMigrations;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/inpn-'.uniqid();
        $this->writeZnieff();
        $this->writeNatura();
        $this->writeAires();
        config(['abc.inpn.dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->rrmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    public function test_import_znieff_filtre_et_normalise(): void
    {
        $service = app(InpnImportService::class);
        $counts = $service->importZnieff();

        $this->assertSame(3, $counts['zones']);
        $this->assertSame(3, $counts['communes']);
        $this->assertSame(3, $counts['especes']);

        $z1 = $this->dbRow('inpn_znieff', ['id' => 100]);
        $this->assertSame('240009732', $z1->id_national);
        $this->assertSame('BOIS DE LOUZAIE', $z1->nom);
        $this->assertSame(1, (int) $z1->ty_zone);
        $this->assertSame('1982-01-01', $z1->date_crea);
        $this->assertEquals(16.36, (float) $z1->surface_ha);
        // Champ multi-lignes conservé intégralement
        $this->assertSame("Texte sur\ndeux lignes", strip_tags($z1->texte_interet));

        // Parent rattaché au second passage, parent inconnu ignoré
        $z2 = $this->dbRow('inpn_znieff', ['id' => 200]);
        $this->assertSame(100, (int) $z2->id_parent);
        $z250 = $this->dbRow('inpn_znieff', ['id' => 250]);
        $this->assertNull($z250->id_parent);

        // La zone supprimée n'existe pas
        $this->assertNull($this->dbRow('inpn_znieff', ['id' => 300]));

        // Codes communes normalisés INSEEC07331 → 07331, Corse 2A004 acceptée
        $codes = $this->dbColumn('inpn_znieff_communes', 'code_commune', ['znieff_id' => 100]);
        $this->assertSame(['07331', '2A004'], $codes);

        // Espèces : déterminante importée, confidentielle flaguée, jamais
        // affichée côté public ; fg_supp exclue.
        $especes = $this->dbRows('inpn_znieff_especes', ['znieff_id' => 100]);
        $this->assertCount(3, $especes);
        // Dictionnaire INPN : D = déterminante, E = enjeux (pas déterminante),
        // C = confidentielle. fg_conf est un second signal de confidentialité.
        $this->assertSame('3489', (string) $especes[0]->cd_ref);
        $this->assertTrue((bool) $especes[0]->determinante);
        $this->assertFalse((bool) $especes[0]->confidentielle);
        $this->assertTrue((bool) $especes[1]->confidentielle); // D + fg_conf
        $this->assertTrue((bool) $especes[1]->determinante);
        $this->assertFalse((bool) $especes[2]->determinante); // E = enjeux
    }

    public function test_import_natura_filtre_et_joint_les_noms(): void
    {
        $service = app(InpnImportService::class);
        $counts = $service->importNatura();

        $this->assertSame(2, $counts['sites']);
        $this->assertSame(2, $counts['communes']);
        $this->assertSame(1, $counts['habitats']);
        $this->assertSame(2, $counts['especes']);

        $zps = $this->dbRow('inpn_natura_sites', ['sitecode' => 'FR8212001']);
        $this->assertSame("DUNES DE L'AUTHIE", $zps->nom);
        $this->assertSame('ZPS', $zps->type_site);
        $this->assertSame('07/2024', $zps->date_dern_modif);

        // Site sans nom dans desigr : null assumé (référentiel incomplet)
        $sic = $this->dbRow('inpn_natura_sites', ['sitecode' => 'FR1100795']);
        $this->assertNull($sic->nom);
        $this->assertSame('SIC', $sic->type_site);

        // Lien commune : statut=false exclu, code invalide exclu
        $communes = $this->dbColumn('inpn_natura_communes', 'code_commune', ['sitecode' => 'FR8212001']);
        $this->assertSame(['32141'], $communes);
        $lien = $this->dbRow('inpn_natura_communes', ['sitecode' => 'FR8212001']);
        $this->assertEquals(32.2, (float) $lien->area_intersect);

        // Habitat : date_supp exclu, champs FSD bruts
        $hab = $this->dbRow('inpn_natura_habitats', ['sitecode' => 'FR8212001']);
        $this->assertSame('2110', $hab->cd_ue);
        $this->assertSame('B', $hab->representativite);
        $this->assertSame('C', $hab->conservation);

        // Oiseau (A###) : nom et annexe I venus du référentiel Oiseaux
        $oiseau = $this->dbRow('inpn_natura_especes', ['sitecode' => 'FR8212001', 'code_n2000' => 'A061']);
        $this->assertSame('Aythya fuligula', $oiseau->nom_scientifique);
        $this->assertSame('Fuligule morillon', $oiseau->nom_vernaculaire);
        $this->assertTrue((bool) $oiseau->annexe_1);
        $this->assertFalse((bool) $oiseau->annexe_ii);
        // Espèce Habitats : nom, annexe II et prioritaire venus du référentiel DHFF
        $loutre = $this->dbRow('inpn_natura_especes', ['sitecode' => 'FR8212001', 'code_n2000' => '1355']);
        $this->assertSame('Lutra lutra', $loutre->nom_scientifique);
        $this->assertSame("Loutre d'Europe", $loutre->nom_vernaculaire);
        $this->assertTrue((bool) $loutre->annexe_ii);
        $this->assertTrue((bool) $loutre->prioritaire);
        $this->assertFalse((bool) $loutre->annexe_1);
    }

    public function test_import_aires_protegees_convertit_cp1252(): void
    {
        $service = app(InpnImportService::class);
        $counts = $service->importAiresProtegees();

        $this->assertSame(1, $counts['aires']);
        $this->assertSame(0, $counts['ignores']);

        $aire = $this->dbRow('inpn_aires_protegees', ['id_mnhn' => 'FR3800927']);
        $this->assertSame('Réserve de la Tête de Bruix', $aire->nom); // cp1252 converti
        $this->assertSame('test', $aire->territoire);
        $this->assertSame('Arrêté de protection de biotope', $aire->type_espace);
        $this->assertTrue((bool) $aire->doc_gestion);
        $this->assertEquals(12.5, (float) $aire->superficie_ha);
        $this->assertSame('2016-11-15', $aire->date_crea_sign);

        // Sans PostGIS (SQLite en test) : skip propre, pas d'erreur
        $this->assertSame(-1, $service->jointureSpatialeAires());
    }

    public function test_commande_import_enregistre_les_versions(): void
    {
        $this->artisan('abc:import-inpn', ['--source' => 'all', '--dir' => $this->dir])
            ->assertExitCode(0);

        $znieff = InpnImport::query()->where('source', 'znieff')->first();
        $this->assertNotNull($znieff);
        $this->assertSame(config('abc.inpn.versions.znieff'), $znieff->version_source);
        $this->assertSame(3, $znieff->nb_entites);

        $natura = InpnImport::query()->where('source', 'natura')->first();
        $this->assertSame(2, $natura->nb_entites);

        $aires = InpnImport::query()->where('source', 'aires')->first();
        $this->assertSame(1, $aires->nb_entites);

        // Idempotent : un second import ne double rien
        Artisan::call('abc:import-inpn', ['--source' => 'all', '--dir' => $this->dir]);
        $this->assertSame(3, $this->dbCount('inpn_znieff'));
        $this->assertSame(3, InpnImport::query()->count());
    }

    public function test_export_portable_vide_puis_reinsere(): void
    {
        app(InpnImportService::class)->importZnieff();

        $out = $this->dir.'/inpn-deploy.sql';
        $counts = app(SqlDumpService::class)->dumpInpn($out);

        $this->assertSame(3, $counts['inpn_znieff']);
        $sql = (string) file_get_contents($out);
        // Les enfants sont vidés avant leurs parents (FK), pas d'ordre fantôme
        $deleteEnfant = strpos($sql, 'DELETE FROM "inpn_znieff_communes";');
        $deleteParent = strpos($sql, 'DELETE FROM "inpn_znieff";');
        $this->assertNotFalse($deleteEnfant);
        $this->assertNotFalse($deleteParent);
        $this->assertLessThan($deleteParent, $deleteEnfant);
        $this->assertStringContainsString('INSERT INTO "inpn_znieff"', $sql);
        $this->assertStringContainsString('BEGIN;', $sql);
        $this->assertStringContainsString('COMMIT;', $sql);
    }

    // ---------------------------------------------------------------- fixtures

    private function writeZnieff(): void
    {
        mkdir($this->dir.'/ZNIEFF', 0777, true);

        $ref = "\xEF\xBB\xBFID_ZNIEFF;NM_SFFZN;NM_SFFZN_PARENT;NM_REGZN;CD_SIG_REG;LB_ZN;TY_ZONE;ALT_MINI;"
            ."ALT_MAXI;SU_ZN;VOLET_ZNIEFF;FG_SUPP;DATE_CREA;DATE_MODIF;TX_INTERET;TX_BILAN;ID_ZNIEFF_PARENT\n"
            .'100;240009732;;00000544;INSEER24;BOIS DE LOUZAIE;1;30;85;16.36;CONT;false;19820101000000;'
            ."20160419105627;\"<p>Texte sur\ndeux lignes</p>\";<p>Bilan</p>;\n"
            ."200;240009733;;00000545;INSEER24;MARSAIS DU TEST;2;0;5;;CONT;false;19900101000000;;\"<p>Second texte</p>\";;100\n"
            ."250;240009735;;00000546;INSEER24;ZONE ORPHELINE;1;;;;CONT;false;19900101000000;;;;999\n"
            ."300;240009734;;00000547;INSEER24;ZONE SUPPRIMEE;1;;;;CONT;true;;20000101000000;;;\n";
        file_put_contents($this->dir.'/ZNIEFF/REF_ZNIEFF.csv', $ref);

        $com = "\xEF\xBB\xBFID_COM;ID_ZNIEFF;NM_SFFZN;CD_SIG_COMMUNE;CD_SIG_DEPT;FG_SUPP;DATE_CREA;DATE_MODIF;CD_SIG_REG\n"
            ."1;100;240009732;INSEEC07331;INSEED07;false;20100608160901;20250604173106;INSEER82\n"
            ."2;100;240009732;INSEEC2A004;INSEED2A;false;20100608160901;20250604173106;INSEER94\n"
            ."3;200;240009733;INSEEC07331;INSEED07;false;20100608160901;20250604173106;INSEER82\n"
            ."4;100;240009732;INSEEC07331;INSEED07;false;20100608160901;20250604173106;INSEER82\n" // doublon
            ."5;300;240009734;INSEEC07331;INSEED07;false;20100608160901;20250604173106;INSEER82\n" // zone supp.
            ."6;100;240009732;INSEECXX333;INSEED07;false;20100608160901;20250604173106;INSEER82\n" // code invalide
            ."7;100;240009732;INSEEC07332;INSEED07;true;20100608160901;20250604173106;INSEER82\n"; // lien supp.
        file_put_contents($this->dir.'/ZNIEFF/REF_COM.csv', $com);

        $esp = '"id_espece";"id_znieff";"nm_sffzn";"cd_ref";"cd_nom";"fg_conf";"fg_esp";"st_vsd";"fg_supp";'
            .'"date_crea";"date_supp";"date_modif";"groupe_taxo";"nom_cite";"id_sinp"'."\n"
            .'"1";"100";"420030368";"3489";"3489";"false";"D";"3";"false";"2012-09-14";;"2025-04-03";"Oiseaux";'
            .'"<i>Otus scops</i> (Linnaeus, 1758)";"ece2"'."\n"
            .'"2";"100";"420030368";"3489";"3489";"true";"D";"3";"false";"2012-09-14";;"2025-04-03";"Oiseaux";'
            .'"<i>Otus scops</i>";"ece3"'."\n"
            .'"3";"100";"420030368";"77";"77";"false";"E";"3";"false";"2012-09-14";;"2025-04-03";"Oiseaux";'
            .'"<i>Exclue</i>";"ece4"'."\n"
            .'"4";"100";"420030368";"2";"2";"false";"C";"3";"true";"2012-09-14";;"2025-04-03";"Oiseaux";'
            .'"<i>Zone inconnue</i>";"ece5"'."\n";
        file_put_contents($this->dir.'/ZNIEFF/REF_ESPECE.csv', $esp);
    }

    private function writeNatura(): void
    {
        $dir = $this->dir.'/NATURA_BDD_122024';
        mkdir($dir, 0777, true);

        file_put_contents($dir.'/info_sig.csv.csv',
            '"id";"cd_sig";"echelle";"ref_carto";"version_ref";"labellisation";"id_couche";"date_dern_modif";"echelle_num";"lb_couche"'."\n"
            .'"FR8212001";"I098FR8212001";"25000";"IGN";;"true";"28";"07/2024";"25000";"ZPS"'."\n"
            .'"FR1100795";"I098FR1100795";"25000";"IGN";;"true";"29";"07/2024";"25000";"SIC"'."\n");

        file_put_contents($dir.'/desigr.csv.csv',
            '"sitecode";"cd_mpro";"des_site";"overlap";"overlap_p";"pk_desigr";"fpk_natura";"date_modif";"date_crea";"nm_spn";"date_base"'."\n"
            .'"FR8212001";"11";"DUNES DE L\'AUTHIE";"+";"1";"18034";"8673";;;"FR1100002";"2022-12-13"'."\n");

        file_put_contents($dir.'/regcode.csv.csv',
            '"sitecode";"cd_insee";"pk_regcode";"fpk_natura";"date_modif";"date_crea";"statut";"date_base";"area_intersect";"cover_intersect";"lb_intersect";"cd_sig";"source"'."\n"
            .'"FR8212001";"32141";"60501";"6886";"2014-12-11";;"true";"2018-06-14";"32.20";"2.98";"P";"INSEEC32141";"txt"'."\n"
            .'"FR1100795";"2A004";"60502";"6887";;;"true";"2018-06-14";"10.50";"1.00";"C";"INSEEC2A004";"txt"'."\n"
            .'"FR8212001";"XXXXX";"60503";"6889";;;"true";"2018-06-14";"1.00";"1.00";"P";"INSEEC99999";"txt"'."\n"
            .'"FR1100795";"32142";"60504";"6890";;;"false";"2018-06-14";"5.00";"1.00";"C";"INSEEC32142";"txt"'."\n");

        file_put_contents($dir.'/habit1.csv.csv',
            '"id_habit1";"sitecode";"cd_ue";"cd_hab";"cover";"represent";"global";"area";"date_supp"'."\n"
            .'"64686";"FR8212001";"2110";"2682";"0.06";"B";"C";"0.80";'."\n"
            .'"64687";"FR8212001";"9999";"9999";"1.00";"B";"C";"1.00";"2020-01-01"'."\n"
            .'"64688";"FRXXXXXXX";"2110";"2682";"0.10";"B";"C";"0.10";'."\n");

        file_put_contents($dir.'/species.csv.csv',
            '"id_species";"sitecode";"code_n2000";"cd_ref";"taxgroup";"annexe_ii";"date_supp"'."\n"
            // A### = oiseaux (directive Oiseaux) ; 1355 = Lutra (directive Habitats).
            .'"95048";"FR8212001";"A061";"1998";"B";"N";'."\n"
            .'"95050";"FR8212001";"1355";"60758";"M";"Y";'."\n"
            .'"95049";"FR8212001";"A234";"100";"B";"N";"2021-05-05"'."\n");

        file_put_contents($dir.'/code_do_oiseaux.csv.csv',
            '"code_n2000";"cd_nom";"cd_ref";"nom_n2000";"lb_nom";"lb_auteur";"nom_vern";"annexe_i"'."\n"
            .'"A061";"1998";"1998";"Aythya fuligula";"";"(Linnaeus, 1758)";"Fuligule morillon";"Y"'."\n");

        file_put_contents($dir.'/dhff_especes.csv.csv',
            '"code_n2000";"cd_nom";"cd_ref";"st_taxon";"nom_eval_2013";"lb_nom";"lb_auteur";"nom_vern";"niv_tax";"prioritaire";"annexe_ii"'."\n"
            .'"1001";"7445";"7445";"NV";"Corallium rubrum";"Corallium rubrum";"(Linnaeus, 1758)";"Corail rouge";"Invertébrés";"false";"N"'."\n"
            .'"1355";"60758";"60758";"NV";"Lutra lutra";"Lutra lutra";"(Linnaeus, 1758)";"Loutre d\'Europe";"Mammifères";"true";"Y"'."\n");

    }

    private function writeAires(): void
    {
        $dir = $this->dir.'/Espaces Protégés';
        mkdir($dir, 0777, true);

        $pdo = new \PDO('sqlite:'.$dir.'/sig_test.gpkg');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE gpkg_contents (table_name TEXT, data_type TEXT)');
        $pdo->exec('CREATE TABLE sig_test ('
            .'fid INTEGER PRIMARY KEY, geom BLOB, id_mnhn TEXT, nom TEXT, date_crea_sign TEXT,'
            .' date_modif_geo TEXT, lien_fiche TEXT, type_espace TEXT, objectif_protection TEXT,'
            .' statut TEXT, superficie_ha REAL, cd_type_milieu TEXT, ref_carto_nom TEXT,'
            .' ref_carto_version TEXT, doc_gestion TEXT)');
        $pdo->exec("INSERT INTO gpkg_contents VALUES ('sig_test', 'features')");
        $nom = mb_convert_encoding('Réserve de la Tête de Bruix', 'Windows-1252', 'UTF-8');
        $type = mb_convert_encoding('Arrêté de protection de biotope', 'Windows-1252', 'UTF-8');
        $stmt = $pdo->prepare('INSERT INTO sig_test (id_mnhn, nom, date_crea_sign, date_modif_geo,'
            .' type_espace, objectif_protection, statut, superficie_ha, cd_type_milieu, doc_gestion)'
            .' VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['FR3800927', $nom, '2016-11-15', $type, 'Nature', null, 12.5, 'MIX', 'true']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return \stdClass|null */
    private function dbRow(string $table, array $where)
    {
        return \DB::table($table)->where($where)->first();
    }

    /** @return array<int, \stdClass> */
    private function dbRows(string $table, array $where): array
    {
        return \DB::table($table)->where($where)->orderBy('id')->get()->all();
    }

    /** @return array<int, string> */
    private function dbColumn(string $table, string $column, array $where): array
    {
        return \DB::table($table)->where($where)->orderBy($column)->pluck($column)->all();
    }

    private function dbCount(string $table): int
    {
        return (int) \DB::table($table)->count();
    }
}
