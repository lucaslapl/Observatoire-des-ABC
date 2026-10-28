<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

/**
 * Import des référentiels INPN (data/inpn-data/, jamais dans git) vers les
 * tables `inpn_*`. Méthodes idempotentes : chaque source est rechargée
 * intégralement (les FK cascadent sur les tables enfants).
 *
 * Ces tables ne sont JAMAIS touchées par le collect : elles sont
 * rechargées à la demande quand une nouvelle base INPN arrive, puis
 * exportées en SQL portable via `abc:export-inpn`.
 */
class InpnImportService
{
    private const BATCH = 500;

    public function __construct(private CsvReaderService $csv) {}

    /**
     * ZNIEFF : zones actives (FG_SUPP=false), communes intersectées et
     * espèces citées (les confidentielles sont importées avec leur flag,
     * jamais affichées).
     *
     * @param  callable(int): void  $tick  appelé tous les BATCH enregistrements
     * @return array{zones: int, communes: int, especes: int}
     */
    public function importZnieff(?callable $tick = null, ?string $dir = null): array
    {
        $dir ??= $this->dir();
        $this->requireFile($dir.'/ZNIEFF/REF_ZNIEFF.csv');

        DB::table('inpn_znieff')->delete(); // cascade communes + especes

        $zones = 0;
        $chunk = [];
        $parents = [];
        $ids = [];
        foreach ($this->csv->iterate($dir.'/ZNIEFF/REF_ZNIEFF.csv') as $rec) {
            $rec = $this->lower($rec);
            if (($rec['fg_supp'] ?? '') === 'true') {
                continue;
            }
            $id = (int) $rec['id_znieff'];
            $ids[$id] = true;
            if (! empty($rec['id_znieff_parent'])) {
                $parents[$id] = (int) $rec['id_znieff_parent'];
            }
            $chunk[] = [
                'id' => $id,
                'id_national' => $rec['nm_sffzn'],
                'ty_zone' => (int) $rec['ty_zone'],
                'nom' => $rec['lb_zn'],
                'surface_ha' => $this->decimal($rec['su_zn'] ?? null),
                'altitude_min' => $this->intOrNull($rec['alt_mini'] ?? null),
                'altitude_max' => $this->intOrNull($rec['alt_maxi'] ?? null),
                'region_sig' => $this->nullIfEmpty($rec['cd_sig_reg'] ?? null),
                'volet' => $this->nullIfEmpty($rec['volet_znieff'] ?? null),
                'id_parent' => null, // second passage (ordre fichier non garanti)
                'texte_interet' => $this->nullIfEmpty($rec['tx_interet'] ?? null),
                'texte_delimitation' => $this->nullIfEmpty($rec['tx_delim'] ?? null),
                'texte_bilan' => $this->nullIfEmpty($rec['tx_bilan'] ?? null),
                'date_crea' => $this->dateInpn($rec['date_crea'] ?? null),
                'date_modif' => $this->dateInpn($rec['date_modif'] ?? null),
            ];
            $zones++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_znieff')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_znieff')->insert($chunk);
        }

        // Second passage : rattacher les ZNIEFF parentes existantes dans le
        // référentiel filtré (un parent supprimé ne peut pas servir de FK).
        // Casts ::int explicites : sans contexte, Postgres infère `text` pour
        // les valeurs du CASE (SQLite ne voit pas la différence, d'où le test
        // réel qui l'a révélé).
        $updates = array_filter($parents, fn (int $p): bool => isset($ids[$p]));
        foreach (array_chunk($updates, self::BATCH, true) as $batch) {
            $sql = 'UPDATE inpn_znieff SET id_parent = CASE id ';
            $bindings = [];
            foreach ($batch as $id => $parent) {
                $sql .= 'WHEN CAST(? AS INTEGER) THEN CAST(? AS INTEGER) ';
                $bindings[] = $id;
                $bindings[] = $parent;
            }
            $sql .= 'END WHERE id IN ('.implode(', ', array_fill(0, count($batch), '?')).')';
            DB::update($sql, array_merge($bindings, array_keys($batch)));
        }

        $communes = $this->importZnieffCommunes($ids, $tick, $dir);
        $especes = $this->importZnieffEspeces($ids, $tick, $dir);

        return ['zones' => $zones, 'communes' => $communes, 'especes' => $especes];
    }

    /**
     * @param  array<int, bool>  $ids  identifiants des zones importées
     * @return int nombre de lignes insérées
     */
    private function importZnieffCommunes(array $ids, ?callable $tick, string $dir): int
    {
        $this->requireFile($dir.'/ZNIEFF/REF_COM.csv');
        DB::table('inpn_znieff_communes')->delete();

        $count = 0;
        $chunk = [];
        $seen = [];
        foreach ($this->csv->iterate($dir.'/ZNIEFF/REF_COM.csv') as $rec) {
            $rec = $this->lower($rec);
            if (($rec['fg_supp'] ?? '') === 'true') {
                continue;
            }
            $znieffId = (int) $rec['id_znieff'];
            if (! isset($ids[$znieffId])) {
                continue; // zone supprimée du référentiel filtré
            }
            $code = $this->codeCommune($rec['cd_sig_commune'] ?? null);
            if ($code === null) {
                continue;
            }
            $key = $znieffId.'|'.$code;
            if (isset($seen[$key])) {
                continue; // doublon : PK composite
            }
            $seen[$key] = true;
            $chunk[] = ['znieff_id' => $znieffId, 'code_commune' => $code];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_znieff_communes')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_znieff_communes')->insert($chunk);
        }

        return $count;
    }

    /**
     * Espèces citées par ZNIEFF (~2,1 M de lignes) : lecture en flux,
     * insertion par lots. Une espèce confidentielle reste importée avec
     * son flag — l'affichage DOIT toujours l'exclure.
     *
     * @param  array<int, bool>  $ids
     * @return int nombre de lignes insérées
     */
    private function importZnieffEspeces(array $ids, ?callable $tick, string $dir): int
    {
        $this->requireFile($dir.'/ZNIEFF/REF_ESPECE.csv');
        DB::table('inpn_znieff_especes')->delete();

        $count = 0;
        $chunk = [];
        foreach ($this->csv->iterate($dir.'/ZNIEFF/REF_ESPECE.csv') as $rec) {
            $rec = $this->lower($rec);
            if (($rec['fg_supp'] ?? '') === 'true') {
                continue;
            }
            $znieffId = (int) $rec['id_znieff'];
            if (! isset($ids[$znieffId])) {
                continue;
            }
            $chunk[] = [
                'znieff_id' => $znieffId,
                'cd_ref' => (int) $rec['cd_ref'],
                'nom_cite' => $rec['nom_cite'],
                'groupe_taxo' => $this->nullIfEmpty($rec['groupe_taxo'] ?? null),
                // Dictionnaire INPN (ZNIEFF_DICTIONNAIRE.xlsx, FG_ESP) :
                // A : autre espèce ; E : autre espèce à enjeux ;
                // D : déterminante ; C : confidentielle.
                'determinante' => ($rec['fg_esp'] ?? '') === 'D',
                'confidentielle' => ($rec['fg_conf'] ?? '') === 'true' || ($rec['fg_esp'] ?? '') === 'C',
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_znieff_especes')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_znieff_especes')->insert($chunk);
        }

        return $count;
    }

    /**
     * Natura 2000 : sites (base 12/2024), communes, habitats et espèces.
     *
     * @return array{sites: int, communes: int, habitats: int, especes: int}
     */
    public function importNatura(?callable $tick = null, ?string $dir = null): array
    {
        $dir ??= $this->dir();
        $infoSig = $dir.'/NATURA_BDD_122024/info_sig.csv.csv';
        $this->requireFile($infoSig);

        DB::table('inpn_natura_sites')->delete(); // cascade communes/habitats/especes

        // Noms des sites : présents uniquement dans desigr (des_site).
        $noms = [];
        if (is_file($dir.'/NATURA_BDD_122024/desigr.csv.csv')) {
            foreach ($this->csv->iterate($dir.'/NATURA_BDD_122024/desigr.csv.csv') as $rec) {
                $rec = $this->lower($rec);
                $sitecode = $rec['sitecode'] ?? '';
                if ($sitecode !== '' && ! isset($noms[$sitecode])) {
                    $noms[$sitecode] = $this->nullIfEmpty($rec['des_site'] ?? null);
                }
            }
        }

        $sites = 0;
        $chunk = [];
        $sitecodes = [];
        foreach ($this->csv->iterate($infoSig) as $rec) {
            $rec = $this->lower($rec);
            $sitecode = $rec['id'] ?? '';
            if ($sitecode === '' || isset($sitecodes[$sitecode])) {
                continue;
            }
            $sitecodes[$sitecode] = true;
            $chunk[] = [
                'sitecode' => $sitecode,
                'nom' => $noms[$sitecode] ?? null,
                'type_site' => $this->nullIfEmpty($rec['lb_couche'] ?? null),
                'cd_sig' => $this->nullIfEmpty($rec['cd_sig'] ?? null),
                'echelle' => $this->nullIfEmpty($rec['echelle'] ?? null),
                'date_dern_modif' => $this->nullIfEmpty($rec['date_dern_modif'] ?? null),
            ];
            $sites++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_natura_sites')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_natura_sites')->insert($chunk);
        }

        $communes = $this->importNaturaCommunes($sitecodes, $tick, $dir);
        $habitats = $this->importNaturaHabitats($sitecodes, $tick, $dir);
        $especes = $this->importNaturaEspeces($sitecodes, $tick, $dir);
        $docob = $this->importNaturaDocob($sitecodes, $dir);

        return ['sites' => $sites, 'communes' => $communes, 'habitats' => $habitats, 'especes' => $especes, 'docob' => $docob];
    }

    /**
     * Documents d'objectifs référencés dans la base INPN
     * (generique_upload) : titre + lien INPN. Le PDF hébergé localement
     * (public/docob/) est préféré à l'affichage.
     *
     * @param  array<string, bool>  $sitecodes
     * @return int nombre de documents importés
     */
    private function importNaturaDocob(array $sitecodes, string $dir): int
    {
        $file = $dir.'/NATURA_BDD_122024/generique_upload.csv.csv';
        if (! is_file($file)) {
            return 0; // fichier optionnel
        }
        DB::table('inpn_natura_docob')->delete();

        $count = 0;
        $chunk = [];
        $seen = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            $filename = mb_strtolower($rec['filename'] ?? '');
            if (! str_contains($filename, 'docob')) {
                continue;
            }
            $sitecode = $rec['sitecode'] ?? '';
            if ($sitecode === '' || ! isset($sitecodes[$sitecode])) {
                continue;
            }
            $titre = $this->nullIfEmpty((string) preg_replace('/\s+/u', ' ', $rec['titre'] ?? ''));
            $lien = $this->nullIfEmpty($rec['link'] ?? null);
            $key = $sitecode.'|'.(string) $titre;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chunk[] = ['sitecode' => $sitecode, 'titre' => $titre, 'lien' => $lien];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_natura_docob')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_natura_docob')->insert($chunk);
        }

        return $count;
    }

    /**
     * @param  array<string, bool>  $sitecodes
     */
    private function importNaturaCommunes(array $sitecodes, ?callable $tick, string $dir): int
    {
        $file = $dir.'/NATURA_BDD_122024/regcode.csv.csv';
        $this->requireFile($file);
        DB::table('inpn_natura_communes')->delete();

        $count = 0;
        $chunk = [];
        $seen = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            if (($rec['statut'] ?? '') !== 'true') {
                continue; // lien retiré du référentiel
            }
            $sitecode = $rec['sitecode'] ?? '';
            if (! isset($sitecodes[$sitecode])) {
                continue;
            }
            $code = $this->codeCommune($rec['cd_insee'] ?? null);
            if ($code === null) {
                continue;
            }
            $key = $sitecode.'|'.$code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chunk[] = [
                'sitecode' => $sitecode,
                'code_commune' => $code,
                'area_intersect' => $this->decimal($rec['area_intersect'] ?? null),
                'cover_intersect' => $this->decimal($rec['cover_intersect'] ?? null),
                'lb_intersect' => $this->nullIfEmpty($rec['lb_intersect'] ?? null),
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_natura_communes')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_natura_communes')->insert($chunk);
        }

        return $count;
    }

    /**
     * @param  array<string, bool>  $sitecodes
     */
    private function importNaturaHabitats(array $sitecodes, ?callable $tick, string $dir): int
    {
        $file = $dir.'/NATURA_BDD_122024/habit1.csv.csv';
        $this->requireFile($file);
        DB::table('inpn_natura_habitats')->delete();

        $referentielHabitats = $this->dhffHabitats($dir);

        $count = 0;
        $chunk = [];
        $seen = []; // rares doublons dans la base INPN (~28) : première occurrence
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            if (! empty($rec['date_supp'] ?? '')) {
                continue;
            }
            $sitecode = $rec['sitecode'] ?? '';
            if (! isset($sitecodes[$sitecode])) {
                continue;
            }
            $key = $sitecode.'|'.($rec['cd_ue'] ?? '').'|'.($rec['cd_hab'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $refHab = $referentielHabitats[$rec['cd_ue'] ?? ''] ?? [];
            $chunk[] = [
                'sitecode' => $sitecode,
                'cd_ue' => $rec['cd_ue'] ?? '',
                'nom' => $refHab['nom'] ?? null,
                'prioritaire' => $refHab['prioritaire'] ?? false,
                'cd_hab' => $this->nullIfEmpty($rec['cd_hab'] ?? null),
                'cover' => $this->decimal($rec['cover'] ?? null),
                'area' => $this->decimal($rec['area'] ?? null),
                'representativite' => $this->char($rec['represent'] ?? null),
                // conserve = structure et fonctions (A/B/C) ; l'évaluation
                // GLOBALE (champ « global ») est distincte.
                'conservation' => $this->char($rec['conserve'] ?? null),
                'evaluation_globale' => $this->char($rec['global'] ?? null),
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_natura_habitats')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_natura_habitats')->insert($chunk);
        }

        return $count;
    }

    /**
     * Espèces des formulaires standards (FSD), enrichies du référentiel
     * de la directive (dhff_especes : noms, annexe II, prioritaire).
     *
     * @param  array<string, bool>  $sitecodes
     */
    private function importNaturaEspeces(array $sitecodes, ?callable $tick, string $dir): int
    {
        $file = $dir.'/NATURA_BDD_122024/species.csv.csv';
        $this->requireFile($file);
        DB::table('inpn_natura_especes')->delete();

        // Référentiels fusionnés : directive Habitats (codes numériques)
        // et directive Oiseaux (codes A###, cf. code_do_oiseaux).
        $referentiel = $this->dhffEspeces($dir) + $this->oiseauxEspeces($dir);

        $count = 0;
        $chunk = [];
        // Le FSD compte une ligne par statut biologique (r/c/w/p) : une
        // même espèce-site apparaît jusqu'à 4 fois. On dédoublonne en
        // fusionnant les statuts dans « presences » (ex. « c,r,w »).
        $uniques = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            if (! empty($rec['date_supp'] ?? '')) {
                continue;
            }
            $sitecode = $rec['sitecode'] ?? '';
            if (! isset($sitecodes[$sitecode])) {
                continue;
            }
            $codeN2000 = $rec['code_n2000'] ?? '';
            $cdRef = $this->intOrNull($rec['cd_ref'] ?? null);
            $key = $sitecode.'|'.$codeN2000.'|'.(string) $cdRef;
            $statut = $this->nullIfEmpty($rec['type'] ?? null);
            if (isset($uniques[$key])) {
                if ($statut !== null && ! in_array($statut, $uniques[$key]['statuts'], true)) {
                    $uniques[$key]['statuts'][] = $statut;
                }

                continue;
            }
            $uniques[$key] = [
                'row' => [
                    'sitecode' => $sitecode,
                    'code_n2000' => $codeN2000,
                    'cd_ref' => $cdRef,
                    'taxgroup' => $this->nullIfEmpty($rec['taxgroup'] ?? null),
                    'annexe_ii' => $this->nullIfEmpty($rec['annexe_ii'] ?? null),
                ],
                'statuts' => $statut !== null ? [$statut] : [],
            ];
        }

        foreach ($uniques as $u) {
            $ref = $referentiel[$u['row']['code_n2000']] ?? [];
            $annexeIi = $u['row']['annexe_ii'] ?? $ref['annexe_ii'] ?? '';
            $statuts = $u['statuts'];
            sort($statuts);
            $chunk[] = [
                'sitecode' => $u['row']['sitecode'],
                'code_n2000' => $u['row']['code_n2000'],
                'cd_ref' => $u['row']['cd_ref'],
                'nom_scientifique' => $ref['nom'] ?? null,
                'nom_vernaculaire' => $ref['vern'] ?? null,
                'taxgroup' => $u['row']['taxgroup'],
                'annexe_ii' => $annexeIi === 'Y' || $annexeIi === 'true',
                'annexe_1' => ($ref['annexe_1'] ?? '') === 'Y',
                'prioritaire' => ($ref['prioritaire'] ?? '') === 'true',
                'presences' => $statuts !== [] ? implode(',', $statuts) : null,
            ];
            $count++;
            if (count($chunk) >= self::BATCH) {
                DB::table('inpn_natura_especes')->insert($chunk);
                $chunk = [];
                $this->tick($tick);
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_natura_especes')->insert($chunk);
        }

        return $count;
    }

    /**
     * Référentiel de la directive Habitats (code N2000 → noms, prioritaire).
     *
     * @return array<string, array{nom: string, vern: string, annexe_ii: string, prioritaire: string}>
     */
    private function dhffEspeces(string $dir): array
    {
        $file = $dir.'/NATURA_BDD_122024/dhff_especes.csv.csv';
        $this->requireFile($file);
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            $code = $rec['code_n2000'] ?? '';
            if ($code === '') {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) $rec['lb_nom'],
                'vern' => (string) ($rec['nom_vern'] ?? ''),
                'annexe_ii' => (string) ($rec['annexe_ii'] ?? ''),
                'prioritaire' => (string) ($rec['prioritaire'] ?? ''),
            ];
        }

        return $ref;
    }

    /**
     * Référentiel des habitats de la directive Habitats (dhff_habitats) :
     * nom français (annexe I, code EUR) et caractère prioritaire.
     *
     * @return array<string, array{nom: string, prioritaire: bool}>
     */
    private function dhffHabitats(string $dir): array
    {
        $file = $dir.'/NATURA_BDD_122024/dhff_habitats.csv.csv';
        if (! is_file($file)) {
            return []; // fichier absent des extractions plus anciennes
        }
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            $code = $rec['cd_ue'] ?? '';
            if ($code === '' || isset($ref[$code])) {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) ($rec['lb_habdh_fr'] ?? $rec['hdname'] ?? ''),
                'prioritaire' => ($rec['prioritaire'] ?? '') === 'true',
            ];
        }

        return $ref;
    }

    /**
     * Référentiel de la directive Oiseaux (code_do_oiseaux) : noms et
     * annexe I. Les codes A### des formulaires (ZPS) n'existent pas dans
     * le référentiel Habitats.
     *
     * @return array<string, array{nom: string, vern: string, annexe_1: string}>
     */
    private function oiseauxEspeces(string $dir): array
    {
        $file = $dir.'/NATURA_BDD_122024/code_do_oiseaux.csv.csv';
        if (! is_file($file)) {
            return []; // fichier absent des extractions plus anciennes
        }
        $ref = [];
        foreach ($this->csv->iterate($file) as $rec) {
            $rec = $this->lower($rec);
            $code = $rec['code_n2000'] ?? '';
            if ($code === '') {
                continue;
            }
            $ref[$code] = [
                'nom' => (string) ($rec['nom_n2000'] ?? $rec['lb_nom'] ?? ''),
                'vern' => (string) ($rec['nom_vern'] ?? ''),
                'annexe_1' => (string) ($rec['annexe_i'] ?? ''),
            ];
        }

        return $ref;
    }

    /**
     * Aires protégées (APA-INPN) : attributs des GPKG. Les GPKG ne portent
     * AUCUN code commune : la table `inpn_aires_protegees_communes` est
     * remplie séparément par la jointure spatiale (voir ci-dessous).
     *
     * @return array{aires: int, ignores: int}
     */
    public function importAiresProtegees(?callable $tick = null, ?string $dir = null): array
    {
        $dir ??= $this->dir();
        $glob = glob($dir.'/Espaces Protégés/*.gpkg') ?: [];
        if ($glob === []) {
            throw new RuntimeException("Aucun GPKG trouvé dans {$dir}/Espaces Protégés/");
        }

        DB::table('inpn_aires_protegees')->delete(); // cascade communes

        $aires = 0;
        $ignores = 0;
        $seen = [];
        $chunk = [];
        foreach ($glob as $gpkg) {
            $territoire = (string) preg_replace('/^sig_|\.\w+$/i', '', basename($gpkg));
            $pdo = new PDO('sqlite:'.$gpkg);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tables = $pdo->query("SELECT table_name FROM gpkg_contents WHERE data_type = 'features'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $select = $pdo->query(
                    'SELECT id_mnhn, nom, type_espace, objectif_protection, statut,'
                    .' superficie_ha, cd_type_milieu, lien_fiche, doc_gestion,'
                    .' date_crea_sign, date_modif_geo FROM "'.$table.'"'
                );
                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $id = $this->utf8($row['id_mnhn'] ?? '');
                    if ($id === '' || isset($seen[$id])) {
                        $ignores++;

                        continue; // id_mnhn dupliqué entre territoires
                    }
                    $seen[$id] = true;
                    $chunk[] = [
                        'id_mnhn' => $id,
                        'territoire' => $territoire,
                        'nom' => $this->utf8($row['nom'] ?? ''),
                        'type_espace' => $this->utf8($row['type_espace'] ?? ''),
                        'objectif_protection' => $this->nullIfEmpty($this->utf8($row['objectif_protection'] ?? '')),
                        'statut' => $this->nullIfEmpty($this->utf8($row['statut'] ?? '')),
                        'superficie_ha' => $this->decimal($this->utf8((string) ($row['superficie_ha'] ?? ''))),
                        'cd_type_milieu' => $this->nullIfEmpty($this->utf8($row['cd_type_milieu'] ?? '')),
                        'lien_fiche' => $this->nullIfEmpty($this->utf8($row['lien_fiche'] ?? '')),
                        'doc_gestion' => strtolower($this->utf8((string) ($row['doc_gestion'] ?? ''))) === 'true',
                        'date_crea_sign' => $this->dateIso($this->utf8((string) ($row['date_crea_sign'] ?? ''))),
                        'date_modif_geo' => $this->dateIso($this->utf8((string) ($row['date_modif_geo'] ?? ''))),
                    ];
                    $aires++;
                    if (count($chunk) >= self::BATCH) {
                        DB::table('inpn_aires_protegees')->insert($chunk);
                        $chunk = [];
                        $this->tick($tick);
                    }
                }
            }
        }
        if ($chunk !== []) {
            DB::table('inpn_aires_protegees')->insert($chunk);
        }

        return ['aires' => $aires, 'ignores' => $ignores];
    }

    /**
     * Jointure spatiale aires protégées ↔ communes (Admin Express).
     *
     * Ne tourne QUE sur PostgreSQL + PostGIS (Docker local) : elle lit les
     * polygones des GPKG et du GeoJSON Admin Express dans des tables
     * temporaires, calcule les intersections en ha, puis remplit la table
     * de codes `inpn_aires_protegees_communes` — seule cette dernière part
     * en production (aucune géométrie dans le dump portable).
     *
     * @return int nombre d'intersections, -1 si indisponible (skip)
     */
    public function jointureSpatialeAires(?string $dir = null): int
    {
        $dir ??= $this->dir();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('inpn_aires_protegees')
            || ! $this->postgis()) {
            return -1;
        }

        $geojson = $this->adminExpressFile($dir);
        if ($geojson === null) {
            return -1;
        }

        DB::statement('DROP TABLE IF EXISTS inpn_tmp_communes');
        DB::statement('CREATE TEMP TABLE inpn_tmp_communes (code char(5) PRIMARY KEY, geom geometry)');
        $chunkCodes = [];
        $chunkGeoms = [];
        foreach ($this->geojsonFeatures($geojson) as $feature) {
            $props = $feature['properties'];
            $code = $this->codeCommune(
                // Admin Express COG PLUS : « codgeo » ; autres éditions : insee_com.
                (string) ($props['codgeo'] ?? $props['insee_com'] ?? $props['code_insee'] ?? $props['INSEE_COM'] ?? '')
            );
            $geometry = json_encode($feature['geometry']);
            if ($code === null || $geometry === false) {
                continue;
            }
            $chunkCodes[] = $code;
            $chunkGeoms[] = $geometry;
            if (count($chunkCodes) >= 100) {
                $this->insertCommunesTemp($chunkCodes, $chunkGeoms);
                $chunkCodes = [];
                $chunkGeoms = [];
            }
        }
        if ($chunkCodes !== []) {
            $this->insertCommunesTemp($chunkCodes, $chunkGeoms);
        }

        $nbCommunes = (int) DB::scalar('SELECT COUNT(*) FROM inpn_tmp_communes');
        if ($nbCommunes === 0) {
            DB::statement('DROP TABLE IF EXISTS inpn_tmp_communes');

            return -1;
        }

        DB::statement('DROP TABLE IF EXISTS inpn_tmp_apa');
        DB::statement('CREATE TEMP TABLE inpn_tmp_apa (id_mnhn varchar(20), srs int, geom geometry)');
        $insert = DB::connection()->getPdo()->prepare(
            'INSERT INTO inpn_tmp_apa (id_mnhn, srs, geom)'
            // Le WKB est passé en hexa : un binding binaire n'est pas
            // transmettable en PARAM_STR sur la connexion pgsql.
            .' VALUES (:id, :srs, ST_SetSRID(ST_GeomFromWKB(decode(:wkb, \'hex\')), :srs))'
        );
        foreach (glob($dir.'/Espaces Protégés/*.gpkg') ?: [] as $gpkg) {
            $pdo = new PDO('sqlite:'.$gpkg);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $layers = $pdo->query(
                'SELECT c.table_name, g.column_name, g.srs_id FROM gpkg_contents c'
                .' JOIN gpkg_geometry_columns g ON g.table_name = c.table_name'
                ." WHERE c.data_type = 'features'"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($layers as $layer) {
                $select = $pdo->prepare(
                    'SELECT id_mnhn, "'.$layer['column_name'].'" AS geom FROM "'.$layer['table_name'].'"'
                );
                $select->execute();
                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $wkb = $this->gpkgWkb((string) ($row['geom'] ?? ''));
                    if ($wkb === '') {
                        continue;
                    }
                    $insert->execute([
                        ':id' => $this->utf8((string) ($row['id_mnhn'] ?? '')),
                        ':srs' => (int) $layer['srs_id'],
                        ':wkb' => bin2hex($wkb),
                    ]);
                }
            }
        }

        // Reprojection unique + index GIST sur les deux tables : une
        // jointure avec ST_Transform dans la condition de jointure ne
        // peut pas utiliser les index spatiaux (test séquentiel de 387
        // millions de paires = plusieurs heures).
        // ST_MakeValid : certains polygones INPN sont invalides
        // (auto-intersection -> TopologyException de GEOS au calcul).
        // ST_CollectionExtract(.., 3) ne garde que les polygones.
        DB::statement('UPDATE inpn_tmp_apa SET geom = ST_CollectionExtract(ST_MakeValid(ST_Transform(geom, 4326)), 3)');
        DB::statement('CREATE INDEX inpn_tmp_apa_geom_idx ON inpn_tmp_apa USING GIST (geom)');
        DB::statement('CREATE INDEX inpn_tmp_communes_geom_idx ON inpn_tmp_communes USING GIST (geom)');
        DB::statement('ANALYZE inpn_tmp_apa');
        DB::statement('ANALYZE inpn_tmp_communes');

        DB::table('inpn_aires_protegees_communes')->delete();
        DB::statement(
            'INSERT INTO inpn_aires_protegees_communes (aire_protegee_id, code_commune, surface_intersection_ha)'
            .' SELECT a.id_mnhn, c.code,'
            // ROUND(x, n) n'existe pas pour double precision : cast numeric.
            .' ROUND((ST_Area(ST_Intersection(a.geom, c.geom)::geography) / 10000)::numeric, 4)'
            .' FROM inpn_tmp_apa a JOIN inpn_tmp_communes c ON ST_Intersects(a.geom, c.geom)'
            .' JOIN inpn_aires_protegees ap ON ap.id_mnhn = a.id_mnhn'
            .' WHERE ST_Area(ST_Intersection(a.geom, c.geom)::geography) > 0'
        );

        $nb = (int) DB::scalar('SELECT COUNT(*) FROM inpn_aires_protegees_communes');

        DB::statement('DROP TABLE IF EXISTS inpn_tmp_communes');
        DB::statement('DROP TABLE IF EXISTS inpn_tmp_apa');

        return $nb;
    }

    /**
     * Extrait le WKB d'un blob binaire GeoPackage (en-tête GP + srs +
     * éventuelle enveloppe), à donner à ST_GeomFromWKB.
     */
    private function gpkgWkb(string $blob): string
    {
        if (strlen($blob) < 8 || substr($blob, 0, 2) !== 'GP') {
            return '';
        }
        $flags = ord($blob[3]);
        $envelope = ($flags >> 1) & 0x07;
        $envelopeBytes = match ($envelope) {
            1 => 32,
            2, 3 => 48,
            4 => 64,
            default => 0,
        };

        return substr($blob, 8 + $envelopeBytes);
    }

    /**
     * Insertion par lots des communes dans la table temporaire (100 par
     * requête : ~34 000 insertions unitaires ralentissent tout).
     *
     * @param  array<int, string>  $codes
     * @param  array<int, string>  $geometries
     */
    private function insertCommunesTemp(array $codes, array $geometries): void
    {
        $placeholders = implode(', ', array_fill(0, count($codes), '(?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))'));
        $bindings = [];
        foreach ($codes as $i => $code) {
            $bindings[] = $code;
            $bindings[] = $geometries[$i];
        }
        DB::insert('INSERT INTO inpn_tmp_communes (code, geom) VALUES '.$placeholders, $bindings);
    }

    /**
     * Parcourt un GeoJSON FeatureCollection en flux : le fichier Admin
     * Express (~42 Mo) ne tient pas en mémoire une fois décodé. On capture
     * chaque objet enfant direct de la racine et on ne rend que ceux de
     * type Feature (les autres membres racine — crs, etc. — sont ignorés).
     *
     * @return \Generator<int, array{properties: array<string, mixed>, geometry: array<string, mixed>|string}>
     */
    private function geojsonFeatures(string $file): \Generator
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Impossible de lire le GeoJSON : {$file}");
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $obj = null;
        while (($char = fgetc($handle)) !== false) {
            if ($inString) {
                if ($obj !== null) {
                    $obj .= $char;
                }
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                if ($obj !== null) {
                    $obj .= $char;
                }

                continue;
            }

            if ($char === '{') {
                $depth++;
                if ($depth === 2) {
                    $obj = '{'; // enfant direct de la racine
                } elseif ($obj !== null) {
                    $obj .= $char;
                }

                continue;
            }

            if ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 1 && $obj !== null) {
                    $obj .= '}'; // accolade fermante de l'objet capturé
                    $feature = json_decode($obj, true);
                    if (is_array($feature) && ($feature['type'] ?? '') === 'Feature') {
                        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                        yield [
                            'properties' => $properties,
                            'geometry' => $feature['geometry'] ?? '',
                        ];
                    }
                    $obj = null;

                    continue;
                }
                if ($obj !== null) {
                    $obj .= $char;
                }
                if ($depth === 0) {
                    break; // fin du FeatureCollection
                }

                continue;
            }

            if ($obj !== null) {
                $obj .= $char;
            }
        }
        fclose($handle);
    }

    /**
     * Fichier GeoJSON Admin Express (communes) dans le dossier de données.
     */
    /**
     * Fichier GeoJSON Admin Express (communes) dans le dossier de données.
     * Les archives publiées contiennent aussi cantons, EPCI, arrondissements
     * (a_can2025, a_epci2025…) et parfois des exports TopoJSON : on exclut
     * les fichiers « topo », on privilégie « commune » ou le préfixe IGN
     * des communes (a_com… / com2025…), puis l'extension .geojson.
     */
    private function adminExpressFile(string $dir): ?string
    {
        $candidates = [];
        foreach ([(string) config('abc.inpn.admin_express_dir'), $dir.'/admin-express'] as $folder) {
            if (is_dir($folder)) {
                foreach (glob($folder.'/*.{geojson,json}', GLOB_BRACE) ?: [] as $f) {
                    if (! str_contains(mb_strtolower(basename($f)), 'topo')) {
                        $candidates[] = $f;
                    }
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $f) {
            if (str_contains(mb_strtolower(basename($f)), 'commune')) {
                return $f;
            }
        }
        foreach ($candidates as $f) {
            if (preg_match('/(^|[^a-z])com([0-9_.]|$)/i', basename($f))) {
                return $f; // a_com2025.geojson, com2025.json…
            }
        }
        foreach ($candidates as $f) {
            if (str_ends_with(mb_strtolower($f), '.geojson')) {
                return $f;
            }
        }

        return $candidates[0];
    }

    private function postgis(): bool
    {
        try {
            return (bool) DB::select("SELECT 1 FROM pg_extension WHERE extname = 'postgis'");
        } catch (\Throwable) {
            return false;
        }
    }

    private function dir(): string
    {
        $dir = (string) config('abc.inpn.dir');
        if (! is_dir($dir)) {
            throw new RuntimeException("Dossier des données INPN introuvable : {$dir} (voir config abc.inpn.dir)");
        }

        return $dir;
    }

    private function requireFile(string $file): void
    {
        if (! is_file($file)) {
            throw new RuntimeException("Fichier INPN introuvable : {$file}");
        }
    }

    /**
     * @param  array<string, string>  $rec
     * @return array<string, string>
     */
    private function lower(array $rec): array
    {
        return array_change_key_case($rec, CASE_LOWER);
    }

    /**
     * Normalise un code commune : INSEEC07331 (ZNIEFF) ou 07331 (Natura)
     * deviennent 07331.
     */
    private function codeCommune(?string $raw): ?string
    {
        $code = strtoupper(trim((string) $raw));
        if (str_starts_with($code, 'INSEEC')) {
            $code = substr($code, 6);
        } elseif (str_starts_with($code, 'COM')) {
            $code = substr($code, 3);
        }
        if (! preg_match('/^([0-9]{2}|2A|2B)[0-9]{3}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * Dates INPN : 19820101000000 (ZNIEFF) ou 2016-11-15 (GPKG) → Y-m-d.
     */
    private function dateInpn(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }

        return $this->dateIso($v);
    }

    private function dateIso(string $value): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Les GPKG APA sont encodés en cp1252 : on convertit si nécessaire.
     */
    private function utf8(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return $value;
    }

    private function decimal(?string $value): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? $value : null;
    }

    private function intOrNull(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    private function char(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value[0];
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function tick(?callable $tick): void
    {
        if ($tick !== null) {
            $tick(self::BATCH);
        }
    }
}
