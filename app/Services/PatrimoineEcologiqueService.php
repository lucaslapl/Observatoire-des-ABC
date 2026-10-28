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
     * @param  array<int, string>  $codes
     * @return array<string, mixed>
     */
    public function pourPerimetre(array $codes): array
    {
        if ($codes === []) {
            return ['present' => false, 'versions' => [], 'nb_communes' => 0, 'znieff' => [], 'natura' => [], 'aires' => []];
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

        $znieff = $this->znieffPourPerimetre($codes, $communesDuPerimetre);
        $natura = $this->naturaPourPerimetre($codes, $communesDuPerimetre);
        $aires = $this->airesPourPerimetre($codes, $communesDuPerimetre);

        return [
            'present' => $znieff !== [] || $natura !== [] || $aires !== [],
            'versions' => $this->versions(),
            'nb_communes' => count($codes),
            'znieff' => $znieff,
            'natura' => $natura,
            'aires' => $aires,
        ];
    }

    /**
     * ZNIEFF touchant au moins une commune du périmètre.
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array<int, array<string, mixed>>
     */
    private function znieffPourPerimetre(array $codes, callable $communesDuPerimetre): array
    {
        $parZone = DB::table('inpn_znieff_communes')
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['znieff_id', 'code_commune'])
            ->groupBy('znieff_id')
            ->map(fn ($rows) => $rows->pluck('code_commune')->all());

        if ($parZone->isEmpty()) {
            return [];
        }

        // Une même espèce est citée pour chaque commune de la zone : le
        // DISTINCT évite de la compter deux fois dès que le périmètre
        // touche plusieurs communes d'une même ZNIEFF.
        $especes = DB::table('inpn_znieff_especes as e')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'e.znieff_id')
            ->whereIn('zc.code_commune', $codes)
            ->where('e.determinante', true)
            ->where('e.confidentielle', false)
            ->groupBy('e.znieff_id')
            ->selectRaw('e.znieff_id, COUNT(DISTINCT e.id) AS n')
            ->pluck('n', 'znieff_id');

        return DB::table('inpn_znieff')
            ->whereIn('id', $parZone->keys())
            ->orderBy('ty_zone')
            ->orderBy('nom')
            ->get(['id', 'id_national', 'nom', 'ty_zone', 'surface_ha'])
            ->map(fn ($z) => [
                'id' => (int) $z->id,
                'id_national' => (string) $z->id_national,
                'nom' => (string) $z->nom,
                'ty_zone' => (int) $z->ty_zone,
                'surface_ha' => $z->surface_ha !== null ? (float) $z->surface_ha : null,
                'nb_especes_determinantes' => (int) ($especes[$z->id] ?? 0),
                'communes' => $communesDuPerimetre($parZone[$z->id] ?? []),
            ])
            ->all();
    }

    /**
     * Sites Natura 2000 touchant au moins une commune du périmètre.
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array<int, array<string, mixed>>
     */
    private function naturaPourPerimetre(array $codes, callable $communesDuPerimetre): array
    {
        $parSite = DB::table('inpn_natura_communes')
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['sitecode', 'code_commune'])
            ->groupBy('sitecode')
            ->map(fn ($rows) => $rows->pluck('code_commune')->all());

        if ($parSite->isEmpty()) {
            return [];
        }

        $habitats = DB::table('inpn_natura_habitats')
            ->whereIn('sitecode', $parSite->keys())
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');
        $especes = DB::table('inpn_natura_especes')
            ->whereIn('sitecode', $parSite->keys())
            ->groupBy('sitecode')
            ->selectRaw('sitecode, COUNT(*) AS n')
            ->pluck('n', 'sitecode');

        return DB::table('inpn_natura_sites')
            ->whereIn('sitecode', $parSite->keys())
            ->orderBy('sitecode')
            ->get(['sitecode', 'nom', 'type_site'])
            ->map(fn ($s) => [
                'sitecode' => (string) $s->sitecode,
                'nom' => $s->nom !== null ? (string) $s->nom : null,
                'type_site' => $s->type_site !== null ? (string) $s->type_site : null,
                'nb_habitats' => (int) ($habitats[$s->sitecode] ?? 0),
                'nb_especes' => (int) ($especes[$s->sitecode] ?? 0),
                'communes' => $communesDuPerimetre($parSite[$s->sitecode] ?? []),
            ])
            ->all();
    }

    /**
     * Aires protégées recouvrant au moins une commune du périmètre. La
     * surface affichée est la somme des recouvrements calculés à l'import
     * sur les communes du périmètre (ordre de grandeur décamétrique).
     *
     * @param  array<int, string>  $codes
     * @param  callable(array<int, string>): array  $communesDuPerimetre
     * @return array<int, array<string, mixed>>
     */
    private function airesPourPerimetre(array $codes, callable $communesDuPerimetre): array
    {
        $parAire = DB::table('inpn_aires_protegees_communes')
            ->whereIn('code_commune', $codes)
            ->orderBy('code_commune')
            ->get(['aire_protegee_id', 'code_commune', 'surface_intersection_ha'])
            ->groupBy('aire_protegee_id');

        if ($parAire->isEmpty()) {
            return [];
        }

        return DB::table('inpn_aires_protegees')
            ->whereIn('id_mnhn', $parAire->keys())
            ->orderBy('nom')
            ->get(['id_mnhn', 'nom', 'type_espace', 'superficie_ha', 'lien_fiche', 'doc_gestion'])
            ->map(function ($a) use ($parAire, $communesDuPerimetre) {
                $rows = $parAire[$a->id_mnhn];

                return [
                    'id_mnhn' => (string) $a->id_mnhn,
                    'nom' => (string) $a->nom,
                    'type_espace' => (string) $a->type_espace,
                    'superficie_ha' => $a->superficie_ha !== null ? (float) $a->superficie_ha : null,
                    // Somme des recouvrements sur les communes retenues :
                    // un aire à cheval sur plusieurs communes du périmètre
                    // n'est pas comptée deux fois (une seule ligne par aire).
                    'surface_perimetre_ha' => (float) $rows->sum('surface_intersection_ha'),
                    'lien_fiche' => $a->lien_fiche !== null ? (string) $a->lien_fiche : null,
                    'doc_gestion' => (bool) $a->doc_gestion,
                    'communes' => $communesDuPerimetre($rows->pluck('code_commune')->all()),
                ];
            })
            ->all();
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
