<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bloc « patrimoine écologique » de la page commune : lecture des FAITS
 * BRUTS des référentiels INPN (tables inpn_*). Aucun indicateur composite
 * n'est calculé — on affiche des comptages et des surfaces, pas un score.
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
