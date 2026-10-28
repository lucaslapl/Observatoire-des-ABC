<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bloc « patrimoine écologique » de la page commune et du périmètre des
 * ABC (fiches projet + page dédiée /abc/{slug}/patrimoine) : lecture des
 * FAITS BRUTS des référentiels INPN (tables inpn_*). Aucun indicateur
 * composite n'est calculé — on affiche des comptages et des surfaces,
 * pas un score.
 *
 * Espèces ZNIEFF confidentielles : jamais exposées, ni comptées.
 */
class PatrimoineEcologiqueService
{
    /** Sections paginées de la page périmètre (/abc/{slug}/patrimoine). */
    private const PAR_PAGE = 25;

    /**
     * @return array{
     *     present: bool,
     *     versions: array<string, string>,
     *     znieff: array<int, array<string, mixed>>,
     *     natura: array<int, array<string, mixed>>,
     *     aires: array<int, array<string, mixed>>
     * }
     */
    public function pourCommune(string $code): array
    {
        $znieff = $this->znieffPourCommune($code);
        $natura = $this->naturaPourCommune($code);
        $aires = $this->airesPourCommune($code);

        return [
            'present' => $znieff !== [] || $natura !== [] || $aires !== [],
            'versions' => $this->versions(),
            'znieff' => $znieff,
            'natura' => $natura,
            'aires' => $aires,
        ];
    }

    /**
     * Résumé du patrimoine écologique d'un périmètre ABC (fiches projet) :
     * comptages seuls, pour annoncer la page dédiée sans la charger.
     *
     * @param  array<int, string>  $codes  codes INSEE des communes du périmètre
     * @return array{present: bool, nb_znieff: int, nb_natura: int, nb_aires: int}
     */
    public function resumePerimetre(array $codes): array
    {
        if ($codes === []) {
            return ['present' => false, 'nb_znieff' => 0, 'nb_natura' => 0, 'nb_aires' => 0];
        }

        $nbZnieff = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codes)
            ->distinct('z.id')
            ->count('z.id');
        $nbNatura = DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codes)
            ->distinct('s.sitecode')
            ->count('s.sitecode');
        $nbAires = DB::table('inpn_aires_protegees as a')
            ->join('inpn_aires_protegees_communes as ac', 'ac.aire_protegee_id', '=', 'a.id_mnhn')
            ->whereIn('ac.code_commune', $codes)
            ->distinct('a.id_mnhn')
            ->count('a.id_mnhn');

        return [
            'present' => $nbZnieff + $nbNatura + $nbAires > 0,
            'nb_znieff' => $nbZnieff,
            'nb_natura' => $nbNatura,
            'nb_aires' => $nbAires,
        ];
    }

    /**
     * Patrimoine écologique agrégé sur le périmètre d'un ABC (page dédiée
     * /abc/{slug}/patrimoine) : ZNIEFF, sites Natura 2000 et aires protégées
     * touchant au moins une commune du périmètre, avec, pour chaque zonage,
     * les communes du périmètre concernées. Fait bruts INPN, espèces
     * confidentielles jamais exposées (cf. pourCommune).
     *
     * Les trois sections sont paginées indépendamment (la fiche d'un ABC
     * intercommunal peut couvrir plus de cent ZNIEFF) : les communes et
     * les comptages ne sont lus que pour les zonages de la page demandée.
     *
     * @param  array<int, string>  $codes
     * @param  int  $pageZnieff  page demandée de la section ZNIEFF (bornée)
     * @param  int  $pageNatura  page demandée de la section Natura 2000
     * @param  int  $pageAires  page demandée de la section aires protégées
     * @return array<string, mixed>
     */
    public function pourPerimetre(array $codes, int $pageZnieff = 1, int $pageNatura = 1, int $pageAires = 1): array
    {
        if ($codes === []) {
            return [
                'present' => false,
                'versions' => [],
                'nb_communes' => 0,
                'znieff' => ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1],
                'natura' => ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1],
                'aires' => ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1],
            ];
        }

        sort($codes);

        // Libellés des communes du périmètre (pour les liens vers les pages
        // communes) : identiques pour toutes les sections.
        $libelles = DB::table('communes')
            ->whereIn('code_geographique', $codes)
            ->whereNotNull('libelle_geographique')
            ->distinct()
            ->pluck('libelle_geographique', 'code_geographique')
            ->all();
        $communesDuPerimetre = fn (array $codesTouches): array => array_values(array_map(
            fn (string $code): array => ['code' => $code, 'libelle' => $libelles[$code] ?? $code],
            $codesTouches
        ));

        $znieff = $this->znieffPourPerimetre($codes, $communesDuPerimetre, $pageZnieff);
        $natura = $this->naturaPourPerimetre($codes, $communesDuPerimetre, $pageNatura);
        $aires = $this->airesPourPerimetre($codes, $communesDuPerimetre, $pageAires);

        return [
            'present' => $znieff['total'] + $natura['total'] + $aires['total'] > 0,
            'versions' => $this->versions(),
            'nb_communes' => count($codes),
            'znieff' => $znieff,
            'natura' => $natura,
            'aires' => $aires,
        ];
    }

    /**
     * ZNIEFF touchant au moins une commune du périmètre (page demandée
     * seulement). Les communes et les espèces ne sont lues que pour les
     * zones de la page : une ZNIEFF sur 92 communes ne charge pas 92
     * libellés si elle n'est pas affichée.
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, par_page: int, pages: int}
     */
    private function znieffPourPerimetre(array $codes, callable $communesDuPerimetre, int $page): array
    {
        $total = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codes)
            ->distinct('z.id')
            ->count('z.id');

        [$page, $pages] = $this->bornes($total, $page);
        if ($total === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1];
        }

        $zones = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codes)
            ->groupBy('z.id', 'z.id_national', 'z.nom', 'z.ty_zone', 'z.surface_ha')
            ->orderBy('z.ty_zone')
            ->orderBy('z.nom')
            ->forPage($page, self::PAR_PAGE)
            ->get(['z.id', 'z.id_national', 'z.nom', 'z.ty_zone', 'z.surface_ha']);

        $ids = $zones->pluck('id')->all();
        $parZone = DB::table('inpn_znieff_communes')
            ->whereIn('znieff_id', $ids)
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['znieff_id', 'code_commune'])
            ->groupBy('znieff_id')
            ->map(fn ($rows) => $rows->pluck('code_commune')->all());

        // Une même espèce est citée pour chaque commune de la zone : le
        // DISTINCT évite de la compter deux fois dès que le périmètre
        // touche plusieurs communes d'une même ZNIEFF.
        $especes = DB::table('inpn_znieff_especes as e')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'e.znieff_id')
            ->whereIn('zc.code_commune', $codes)
            ->whereIn('e.znieff_id', $ids)
            ->where('e.determinante', true)
            ->where('e.confidentielle', false)
            ->groupBy('e.znieff_id')
            ->selectRaw('e.znieff_id, COUNT(DISTINCT e.id) AS n')
            ->pluck('n', 'znieff_id');

        $items = $zones->map(fn ($z) => [
            'id' => (int) $z->id,
            'id_national' => (string) $z->id_national,
            'nom' => (string) $z->nom,
            'ty_zone' => (int) $z->ty_zone,
            'surface_ha' => $z->surface_ha !== null ? (float) $z->surface_ha : null,
            'nb_especes_determinantes' => (int) ($especes[$z->id] ?? 0),
            'communes' => $communesDuPerimetre($parZone[$z->id] ?? []),
        ])->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'par_page' => self::PAR_PAGE, 'pages' => $pages];
    }

    /**
     * Sites Natura 2000 touchant au moins une commune du périmètre (page
     * demandée seulement).
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, par_page: int, pages: int}
     */
    private function naturaPourPerimetre(array $codes, callable $communesDuPerimetre, int $page): array
    {
        $total = DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codes)
            ->distinct('s.sitecode')
            ->count('s.sitecode');

        [$page, $pages] = $this->bornes($total, $page);
        if ($total === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1];
        }

        $sites = DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codes)
            ->groupBy('s.sitecode', 's.nom', 's.type_site')
            ->orderBy('s.sitecode')
            ->forPage($page, self::PAR_PAGE)
            ->get(['s.sitecode', 's.nom', 's.type_site']);

        $codesSites = $sites->pluck('sitecode')->all();
        $parSite = DB::table('inpn_natura_communes')
            ->whereIn('sitecode', $codesSites)
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['sitecode', 'code_commune'])
            ->groupBy('sitecode')
            ->map(fn ($rows) => $rows->pluck('code_commune')->all());

        $habitats = DB::table('inpn_natura_habitats')
            ->whereIn('sitecode', $codesSites)
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');
        $especes = DB::table('inpn_natura_especes')
            ->whereIn('sitecode', $codesSites)
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');

        $items = $sites->map(fn ($s) => [
            'sitecode' => (string) $s->sitecode,
            'nom' => $s->nom !== null ? (string) $s->nom : null,
            'type_site' => $s->type_site !== null ? (string) $s->type_site : null,
            'nb_habitats' => (int) ($habitats[$s->sitecode] ?? 0),
            'nb_especes' => (int) ($especes[$s->sitecode] ?? 0),
            'communes' => $communesDuPerimetre($parSite[$s->sitecode] ?? []),
        ])->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'par_page' => self::PAR_PAGE, 'pages' => $pages];
    }

    /**
     * Aires protégées recouvrant au moins une commune du périmètre (page
     * demandée seulement). La surface affichée est la somme des
     * recouvrements calculés à l'import sur les communes du périmètre
     * (ordre de grandeur décamétrique).
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, par_page: int, pages: int}
     */
    private function airesPourPerimetre(array $codes, callable $communesDuPerimetre, int $page): array
    {
        $total = DB::table('inpn_aires_protegees as a')
            ->join('inpn_aires_protegees_communes as ac', 'ac.aire_protegee_id', '=', 'a.id_mnhn')
            ->whereIn('ac.code_commune', $codes)
            ->distinct('a.id_mnhn')
            ->count('a.id_mnhn');

        [$page, $pages] = $this->bornes($total, $page);
        if ($total === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'par_page' => self::PAR_PAGE, 'pages' => 1];
        }

        $aires = DB::table('inpn_aires_protegees as a')
            ->join('inpn_aires_protegees_communes as ac', 'ac.aire_protegee_id', '=', 'a.id_mnhn')
            ->whereIn('ac.code_commune', $codes)
            ->groupBy('a.id_mnhn', 'a.nom', 'a.type_espace', 'a.superficie_ha', 'a.lien_fiche', 'a.doc_gestion')
            ->orderBy('a.nom')
            ->forPage($page, self::PAR_PAGE)
            ->get(['a.id_mnhn', 'a.nom', 'a.type_espace', 'a.superficie_ha', 'a.lien_fiche', 'a.doc_gestion']);

        $parAire = DB::table('inpn_aires_protegees_communes')
            ->whereIn('aire_protegee_id', $aires->pluck('id_mnhn'))
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['aire_protegee_id', 'code_commune', 'surface_intersection_ha'])
            ->groupBy('aire_protegee_id');

        $items = $aires->map(function ($a) use ($parAire, $communesDuPerimetre) {
            $rows = $parAire[$a->id_mnhn] ?? collect();

            return [
                'id_mnhn' => (string) $a->id_mnhn,
                'nom' => (string) $a->nom,
                'type_espace' => (string) $a->type_espace,
                'superficie_ha' => $a->superficie_ha !== null ? (float) $a->superficie_ha : null,
                // Somme des recouvrements sur les communes retenues : un
                // aire à cheval sur plusieurs communes du périmètre n'est
                // pas comptée deux fois (une seule ligne par aire).
                'surface_perimetre_ha' => (float) $rows->sum('surface_intersection_ha'),
                'lien_fiche' => $a->lien_fiche !== null ? (string) $a->lien_fiche : null,
                'doc_gestion' => (bool) $a->doc_gestion,
                'communes' => $communesDuPerimetre($rows->pluck('code_commune')->all()),
            ];
        })->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'par_page' => self::PAR_PAGE, 'pages' => $pages];
    }

    /**
     * Bornage d'une page demandée sur [1, pages] (pages ≥ 1 même sans
     * résultat, pour garder une URL canonique).
     *
     * @return array{0: int, 1: int}
     */
    private function bornes(int $total, int $page): array
    {
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));

        return [min(max(1, $page), $pages), $pages];
    }

    /**
     * Date de référence de chaque base INPN importée (inpn_imports) :
     * affichée avec l'attribution.
     *
     * @return array<string, string>
     */
    private function versions(): array
    {
        return DB::table('inpn_imports')->pluck('version_source', 'source')->all();
    }

    /**
     * ZNIEFF intersectant la commune (référentiel officiel INPN ZNIEFF↔communes),
     * avec le nombre d'espèces déterminantes citées (hors confidentielles).
     *
     * @return array<int, array<string, mixed>>
     */
    private function znieffPourCommune(string $code): array
    {
        $zones = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->where('zc.code_commune', $code)
            ->orderBy('z.ty_zone')
            ->orderBy('z.nom')
            ->get(['z.id', 'z.id_national', 'z.nom', 'z.ty_zone', 'z.surface_ha']);

        if ($zones->isEmpty()) {
            return [];
        }

        $especes = DB::table('inpn_znieff_especes as e')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'e.znieff_id')
            ->where('zc.code_commune', $code)
            ->where('e.determinante', true)
            ->where('e.confidentielle', false)
            ->groupBy('e.znieff_id')
            ->selectRaw('e.znieff_id, COUNT(*) AS n')
            ->pluck('n', 'znieff_id');

        return $zones->map(fn ($z) => [
            'id' => (int) $z->id,
            'id_national' => (string) $z->id_national,
            'nom' => (string) $z->nom,
            'ty_zone' => (int) $z->ty_zone,
            'surface_ha' => $z->surface_ha !== null ? (float) $z->surface_ha : null,
            'nb_especes_determinantes' => (int) ($especes[$z->id] ?? 0),
        ])->all();
    }

    /**
     * Sites Natura 2000 touchant la commune (référentiel INPN regcode), avec
     * les nombres d'habitats et d'espèces de la directive recensés sur le
     * site (formulaire standard de données).
     *
     * @return array<int, array<string, mixed>>
     */
    private function naturaPourCommune(string $code): array
    {
        $sites = DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->where('nc.code_commune', $code)
            ->orderBy('s.sitecode')
            ->get(['s.sitecode', 's.nom', 's.type_site']);

        if ($sites->isEmpty()) {
            return [];
        }

        $codes = $sites->pluck('sitecode')->all();
        $habitats = DB::table('inpn_natura_habitats')
            ->whereIn('sitecode', $codes)
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');
        $especes = DB::table('inpn_natura_especes')
            ->whereIn('sitecode', $codes)
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');

        return $sites->map(fn ($s) => [
            'sitecode' => (string) $s->sitecode,
            'nom' => $s->nom !== null ? (string) $s->nom : null,
            'type_site' => $s->type_site !== null ? (string) $s->type_site : null,
            'nb_habitats' => (int) ($habitats[$s->sitecode] ?? 0),
            'nb_especes' => (int) ($especes[$s->sitecode] ?? 0),
        ])->all();
    }

    /**
     * Aires protégées recouvrant la commune (APA-INPN). Les surfaces
     * d'intersection viennent de la jointure spatiale calculée à l'import
     * (Admin Express) : ordre de grandeur décamétrique, à présenter comme tel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function airesPourCommune(string $code): array
    {
        $aires = DB::table('inpn_aires_protegees as a')
            ->join('inpn_aires_protegees_communes as ac', 'ac.aire_protegee_id', '=', 'a.id_mnhn')
            ->where('ac.code_commune', $code)
            ->orderBy('a.nom')
            ->get([
                'a.id_mnhn', 'a.nom', 'a.type_espace', 'a.superficie_ha',
                'a.lien_fiche', 'a.doc_gestion', 'ac.surface_intersection_ha',
            ]);

        return $aires->map(fn ($a) => [
            'id_mnhn' => (string) $a->id_mnhn,
            'nom' => (string) $a->nom,
            'type_espace' => (string) $a->type_espace,
            'superficie_ha' => $a->superficie_ha !== null ? (float) $a->superficie_ha : null,
            'surface_intersection_ha' => $a->surface_intersection_ha !== null ? (float) $a->surface_intersection_ha : null,
            'lien_fiche' => $a->lien_fiche !== null ? (string) $a->lien_fiche : null,
            'doc_gestion' => (bool) $a->doc_gestion,
        ])->all();
    }
}
