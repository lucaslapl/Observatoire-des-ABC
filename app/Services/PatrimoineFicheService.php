<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fiches publiques « patrimoine écologique » : ZNIEFF et sites Natura
 * 2000, UNIQUEMENT ceux qui touchent au moins une commune ayant un ABC
 * (décision éditoriale : le site n'a pas vocation à refléter les 20 000
 * fiches INPN, mais à mettre l'inventaire national en regard des ABC).
 *
 * Tout ce qui est affiché est un fait brut du référentiel INPN. Les
 * textes INPN (HTML) sont assainis avant d'atteindre le front, et les
 * espèces confidentielles ne sortent jamais de la base vers une fiche.
 */
class PatrimoineFicheService
{
    private const PAR_PAGE = 100;

    /**
     * Page d'accueil du patrimoine (/patrimoine) : volumes éligibles et
     * versions de référence des bases INPN.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $codes = $this->codesCommunesAbc();

        return [
            'nb_znieff' => $this->nbZnieff($codes),
            'nb_natura' => $this->nbNatura($codes),
            'versions' => DB::table('inpn_imports')->pluck('version_source', 'source')->all(),
        ];
    }

    /**
     * Liste paginée des ZNIEFF éligibles.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, par_page: int, pages: int}
     */
    public function znieffListing(int $page): array
    {
        $codes = $this->codesCommunesAbc();

        $total = $this->nbZnieff($codes);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = min(max(1, $page), $pages);

        $items = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codes)
            ->groupBy('z.id', 'z.id_national', 'z.nom', 'z.ty_zone', 'z.surface_ha')
            ->orderBy('z.nom')
            ->forPage($page, self::PAR_PAGE)
            ->get([
                'z.id', 'z.id_national', 'z.nom', 'z.ty_zone', 'z.surface_ha',
                DB::raw('COUNT(zc.code_commune) AS nb_communes_abc'),
            ])
            ->map(fn ($z) => [
                'id_national' => (string) $z->id_national,
                'nom' => (string) $z->nom,
                'ty_zone' => (int) $z->ty_zone,
                'surface_ha' => $z->surface_ha !== null ? (float) $z->surface_ha : null,
                'nb_communes_abc' => (int) $z->nb_communes_abc,
            ])
            ->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'par_page' => self::PAR_PAGE, 'pages' => $pages];
    }

    /**
     * Fiche ZNIEFF (/patrimoine/znieff/{id_national}). 404 si la zone
     * n'existe pas ou ne touche aucune commune ABC.
     *
     * @return array<string, mixed>
     */
    public function znieff(string $idNational): array
    {
        $zone = DB::table('inpn_znieff')->where('id_national', $idNational)->first();
        if ($zone === null) {
            abort(404);
        }

        $codes = $this->codesCommunesAbc();
        $communes = DB::table('inpn_znieff_communes')
            ->where('znieff_id', $zone->id)
            ->orderBy('code_commune')
            ->pluck('code_commune');

        $abcCommunes = $communes->intersect($codes);
        if ($abcCommunes->isEmpty()) {
            abort(404); // hors périmètre ABC : pas de fiche publique
        }

        // Libellés des communes ABC (seules à avoir une page sur le site).
        $labels = DB::table('communes')
            ->whereIn('code_geographique', $abcCommunes)
            ->whereNotNull('libelle_geographique')
            ->distinct()
            ->pluck('libelle_geographique', 'code_geographique');

        // Espèces déterminantes non confidentielles, groupées par taxon.
        $groupes = [];
        foreach (DB::table('inpn_znieff_especes')
            ->where('znieff_id', $zone->id)
            ->where('determinante', true)
            ->where('confidentielle', false)
            ->orderBy('groupe_taxo')
            ->orderBy('nom_cite')
            ->get(['groupe_taxo', 'nom_cite']) as $e) {
            $groupes[$e->groupe_taxo ?: 'Autres'][] = $this->htmlInpn($e->nom_cite);
        }

        $parent = null;
        if ($zone->id_parent !== null) {
            $p = DB::table('inpn_znieff as z')
                ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
                ->where('z.id', $zone->id_parent)
                ->whereIn('zc.code_commune', $codes)
                ->first(['z.id_national', 'z.nom']);
            if ($p !== null) {
                $parent = ['id_national' => (string) $p->id_national, 'nom' => (string) $p->nom];
            }
        }

        $enfants = DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->where('z.id_parent', $zone->id)
            ->whereIn('zc.code_commune', $codes)
            ->groupBy('z.id', 'z.id_national', 'z.nom')
            ->orderBy('z.nom')
            ->get(['z.id_national', 'z.nom'])
            ->map(fn ($e) => ['id_national' => (string) $e->id_national, 'nom' => (string) $e->nom])
            ->all();

        return [
            'id_national' => (string) $zone->id_national,
            'nom' => (string) $zone->nom,
            'ty_zone' => (int) $zone->ty_zone,
            'surface_ha' => $zone->surface_ha !== null ? (float) $zone->surface_ha : null,
            'altitude_min' => $zone->altitude_min !== null ? (int) $zone->altitude_min : null,
            'altitude_max' => $zone->altitude_max !== null ? (int) $zone->altitude_max : null,
            'texte_interet' => $this->htmlInpn($zone->texte_interet),
            'texte_delimitation' => $this->htmlInpn($zone->texte_delimitation),
            'texte_bilan' => $this->htmlInpn($zone->texte_bilan),
            'communes' => $abcCommunes->map(fn ($code) => [
                'code' => $code,
                'libelle' => $labels[$code] ?? $code,
            ])->values()->all(),
            'nb_communes_total' => $communes->count(),
            'groupes_especes' => array_map(
                fn (string $g, array $noms) => ['groupe' => $g, 'noms' => $noms, 'n' => count($noms)],
                array_keys($groupes),
                array_values($groupes)
            ),
            'parent' => $parent,
            'enfants' => $enfants,
        ];
    }

    /**
     * Liste paginée des sites Natura 2000 éligibles.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, par_page: int, pages: int}
     */
    public function naturaListing(int $page): array
    {
        $codes = $this->codesCommunesAbc();

        $total = $this->nbNatura($codes);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = min(max(1, $page), $pages);

        $items = DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codes)
            ->groupBy('s.sitecode', 's.nom', 's.type_site')
            ->orderBy('s.sitecode')
            ->forPage($page, self::PAR_PAGE)
            ->get([
                's.sitecode', 's.nom', 's.type_site',
                DB::raw('COUNT(nc.code_commune) AS nb_communes_abc'),
            ])
            ->map(fn ($s) => [
                'sitecode' => (string) $s->sitecode,
                'nom' => $s->nom !== null ? (string) $s->nom : null,
                'type_site' => $s->type_site !== null ? (string) $s->type_site : null,
                'nb_communes_abc' => (int) $s->nb_communes_abc,
            ])
            ->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'par_page' => self::PAR_PAGE, 'pages' => $pages];
    }

    /**
     * Fiche Natura 2000 (/patrimoine/natura/{sitecode}).
     *
     * @return array<string, mixed>
     */
    public function natura(string $sitecode): array
    {
        $site = DB::table('inpn_natura_sites')->where('sitecode', $sitecode)->first();
        if ($site === null) {
            abort(404);
        }

        $codes = $this->codesCommunesAbc();
        $communes = DB::table('inpn_natura_communes')
            ->where('sitecode', $sitecode)
            ->orderBy('code_commune')
            ->pluck('code_commune');

        $abcCommunes = $communes->intersect($codes);
        if ($abcCommunes->isEmpty()) {
            abort(404); // hors périmètre ABC : pas de fiche publique
        }

        $labels = DB::table('communes')
            ->whereIn('code_geographique', $abcCommunes)
            ->whereNotNull('libelle_geographique')
            ->distinct()
            ->pluck('libelle_geographique', 'code_geographique');

        $habitats = DB::table('inpn_natura_habitats')
            ->where('sitecode', $sitecode)
            ->orderBy('cd_ue')
            ->get(['cd_ue', 'cd_hab', 'representativite', 'conservation'])
            ->map(fn ($h) => [
                'cd_ue' => (string) $h->cd_ue,
                'cd_hab' => $h->cd_hab !== null ? (string) $h->cd_hab : null,
                'representativite' => $h->representativite !== null ? (string) $h->representativite : null,
                'conservation' => $h->conservation !== null ? (string) $h->conservation : null,
            ])
            ->all();

        $especes = DB::table('inpn_natura_especes')
            ->where('sitecode', $sitecode)
            ->orderBy('nom_scientifique')
            ->get(['code_n2000', 'nom_scientifique', 'nom_vernaculaire', 'annexe_ii', 'prioritaire'])
            ->map(fn ($e) => [
                'nom_scientifique' => $e->nom_scientifique !== null ? (string) $e->nom_scientifique : null,
                'nom_vernaculaire' => $e->nom_vernaculaire !== null ? (string) $e->nom_vernaculaire : null,
                'annexe_ii' => (bool) $e->annexe_ii,
                'prioritaire' => (bool) $e->prioritaire,
            ])
            ->all();

        // DOCOB : PDF hébergé localement (public/docob/) en priorité,
        // sinon le document référencé dans la base INPN.
        $pdf = public_path('docob/'.$sitecode.'.pdf');
        $docob = DB::table('inpn_natura_docob')
            ->where('sitecode', $sitecode)
            ->orderByDesc('id')
            ->get(['titre', 'lien'])
            ->map(fn ($d) => [
                'titre' => $d->titre !== null ? (string) $d->titre : 'Document d\'objectifs',
                'lien' => $d->lien !== null ? (string) $d->lien : null,
            ])
            ->all();

        return [
            'sitecode' => (string) $site->sitecode,
            'nom' => $site->nom !== null ? (string) $site->nom : null,
            'type_site' => $site->type_site !== null ? (string) $site->type_site : null,
            'pdf_local' => is_file($pdf) ? '/docob/'.$sitecode.'.pdf' : null,
            'docob' => $docob,
            'communes' => $abcCommunes->map(fn ($code) => [
                'code' => $code,
                'libelle' => $labels[$code] ?? $code,
            ])->values()->all(),
            'nb_communes_total' => $communes->count(),
            'habitats' => $habitats,
            'especes' => $especes,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    /**
     * @return Collection<int, string>
     */
    private function codesCommunesAbc(): Collection
    {
        return DB::table('communes')->distinct()->pluck('code_geographique');
    }

    private function nbZnieff(Collection $codes): int
    {
        return DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codes)
            ->distinct('z.id')
            ->count('z.id');
    }

    private function nbNatura(Collection $codes): int
    {
        return DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codes)
            ->distinct('s.sitecode')
            ->count('s.sitecode');
    }

    /**
     * Assainit le HTML des textes INPN : seules quelques balises de mise
     * en forme sont conservées, et TOUT attribut est retiré (strip_tags
     * garde les attributs des balles autorisées — on ne prend pas ce
     * risque avec du contenu tiers, même officiel).
     */
    private function htmlInpn(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><sup><sub>');
        $html = (string) preg_replace('/<([a-z0-9]+)[^>]*>/i', '<$1>', $html);

        return trim($html) !== '' ? trim($html) : null;
    }
}
