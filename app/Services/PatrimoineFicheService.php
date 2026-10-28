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

    public function __construct(private DepartementService $departements) {}

    /** Accord d'un mot selon un nombre (« commune(s) », « engagée(s) »). */
    private function pl(int $n): string
    {
        return $n > 1 ? 's' : '';
    }

    /** Entier au format français (espace insécable pour les milliers). */
    private function nb(int|float|null $v): string
    {
        return number_format((float) $v, 0, ',', "\u{00A0}");
    }

    /**
     * Libellés des départements des communes ABC concernées, dérivés du
     * préfixe INSEE (indépendant de la colonne `departement`, parfois vide).
     *
     * @param  Collection<int, string>  $codes
     * @return array<int, string>
     */
    private function departements(Collection $codes): array
    {
        $labels = [];
        foreach ($codes as $code) {
            $dep = $this->departements->codeDepuisInsee($code);
            if ($dep !== null) {
                $labels[$dep] = $this->departements->label($dep);
            }
        }
        ksort($labels);

        return array_values(array_filter($labels, fn ($l) => $l !== null));
    }

    /**
     * « X, Y et Z » pour 3 libellés au plus ; au-delà, les 3 premiers
     * suivis du nombre des autres (« A, B, C et 12 autres »).
     *
     * @param  array<int, array{code: string, libelle: string}>  $communes
     */
    private function listeCommunesAbc(array $communes): string
    {
        $libelles = array_values(array_map(fn ($c) => $c['libelle'], $communes));
        $n = count($libelles);

        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $libelles[0];
        }
        if ($n === 2) {
            return $libelles[0].' et '.$libelles[1];
        }
        if ($n === 3) {
            return $libelles[0].', '.$libelles[1].' et '.$libelles[2];
        }

        return implode(', ', array_slice($libelles, 0, 3)).' et '.($n - 3).' autre'.$this->pl($n - 3);
    }

    /**
     * Phrase de périmètre commune aux deux familles de fiches : communes
     * totales, communes ABC et départements concernés.
     *
     * @param  array<int, array{code: string, libelle: string}>  $communesAbc
     * @param  array<int, string>  $departs
     */
    private function phraseCommunes(string $sujet, array $communesAbc, int $nbCommunesTotal, array $departs): string
    {
        $nAbc = count($communesAbc);
        $phrase = $sujet.' recouvre '
            .$this->nb($nbCommunesTotal).' commune'.$this->pl($nbCommunesTotal)
            .', dont '.$this->nb($nAbc).' engagée'.$this->pl($nAbc)
            .' dans un Atlas de la biodiversité communale : '
            .$this->listeCommunesAbc($communesAbc);

        if ($departs !== []) {
            $phrase .= ' ('.(count($departs) > 1 ? 'départements : ' : 'département : ')
                .implode(', ', $departs).')';
        }

        return $phrase.'.';
    }

    /**
     * Chapeau ZNIEFF généré à partir des seuls faits de la fiche. Les
     * fiches sans ce texte se résumeraient à des tables, jugées « thin
     * content » par les moteurs de recherche.
     *
     * @param  array<int, array{code: string, libelle: string}>  $communesAbc
     * @param  array<int, array{groupe: string, noms: array<int, string>, n: int}>  $groupes
     */
    private function chapeauZnieff(object $zone, array $communesAbc, int $nbCommunesTotal, array $groupes): string
    {
        $typeTxt = (int) $zone->ty_zone === 1
            ? 'un secteur d\'intérêt biologique remarquable'
            : 'un grand ensemble naturel riche et peu modifié';

        $phrases = ['La ZNIEFF de type '.(int) $zone->ty_zone.' « '.$zone->nom.' » (identifiant national '
            .$zone->id_national.') est '.$typeTxt];

        if ($zone->surface_ha !== null) {
            $phrases[0] .= ' qui s\'étend sur '.$this->nb($zone->surface_ha).' ha';
            if ($zone->altitude_min !== null && $zone->altitude_max !== null) {
                $phrases[0] .= ', entre '.$this->nb($zone->altitude_min).' et '.$this->nb($zone->altitude_max).' m d\'altitude';
            }
        }
        $phrases[0] .= '.';

        $phrases[] = $this->phraseCommunes('Elle', $communesAbc, $nbCommunesTotal, $this->departements(collect(array_column($communesAbc, 'code'))));

        $nbEspeces = array_sum(array_column($groupes, 'n'));
        if ($nbEspeces > 0) {
            // Groupes mis en avant par effectif décroissant (et non dans
            // l'ordre alphabétique d'affichage du tableau).
            $groupeTxt = array_column(
                collect($groupes)
                    ->filter(fn (array $g) => $g['groupe'] !== 'Autres')
                    ->sortByDesc('n')
                    ->values()
                    ->all(),
                'groupe'
            );
            $phrase = 'L\'inventaire national justifie ce classement par '.$this->nb($nbEspeces)
                .' espèce'.$this->pl($nbEspeces).' déterminante'.$this->pl($nbEspeces);
            if ($groupeTxt !== []) {
                $liste = array_map(fn (string $g) => 'les '.$g, array_slice($groupeTxt, 0, 3));
                $dernier = array_pop($liste);
                $phrase .= ', principalement chez '.implode(', ', $liste).(count($liste) > 0 ? ' et ' : '').$dernier;
            }
            $phrases[] = $phrase.'.';
        }

        return implode(' ', $phrases);
    }

    /**
     * Chapeau Natura 2000 généré à partir des seuls faits du formulaire
     * standard de données (habitats, espèces, périmètre).
     *
     * @param  array<int, array{code: string, libelle: string}>  $communesAbc
     * @param  array<int, array<string, mixed>>  $habitats
     * @param  array<int, array<string, mixed>>  $especes
     */
    private function chapeauNatura(object $site, array $communesAbc, int $nbCommunesTotal, array $habitats, array $especes): string
    {
        $typeTxt = $site->type_site === 'ZPS'
            ? 'une zone de protection spéciale (directive « Oiseaux »)'
            : 'une zone spéciale de conservation (directive « Habitats »)';
        $nom = $site->nom !== null ? ' « '.$site->nom.' »' : '';

        $phrases = ['Le site Natura 2000'.$nom.' ('.$site->sitecode.') est '.$typeTxt.' du réseau européen Natura 2000.'];

        $phrases[] = $this->phraseCommunes('Il', $communesAbc, $nbCommunesTotal, $this->departements(collect(array_column($communesAbc, 'code'))));

        if ($habitats !== []) {
            $nbPrioritaires = count(array_filter($habitats, fn ($h) => $h['prioritaire'] === true));
            $phrase = 'Le formulaire standard de données recense '.$this->nb(count($habitats))
                .' habitat'.$this->pl(count($habitats)).' d\'intérêt communautaire';
            if ($nbPrioritaires > 0) {
                $phrase .= ', dont '.$this->nb($nbPrioritaires).' prioritaire'.$this->pl($nbPrioritaires);
            }
            $noms = array_values(array_filter(array_column($habitats, 'nom'), fn ($n) => $n !== null));
            if ($noms !== []) {
                if (count($noms) > 3) {
                    $phrase .= ' — notamment '.implode(', ', array_slice($noms, 0, 3)).'…';
                } elseif (count($noms) === 1) {
                    $phrase .= ' : '.$noms[0];
                } else {
                    $phrase .= ' — '.implode(', ', array_slice($noms, 0, -1)).' et '.end($noms);
                }
            }
            $phrases[] = $phrase.'.';
        }

        if ($especes !== []) {
            $nbPrioritaires = count(array_filter($especes, fn ($e) => $e['prioritaire'] === true));
            $nbOiseaux = count(array_filter($especes, fn ($e) => str_starts_with((string) $e['code_n2000'], 'A')));
            $phrase = 'Il mentionne également '.$this->nb(count($especes)).' espèce'.$this->pl(count($especes))
                .' des directives';
            if ($nbOiseaux > 0) {
                // Pluriel irrégulier : « oiseaux », pas « oiseaus ». Sur une
                // ZPS, toutes les espèces listées sont des oiseaux : la
                // tournure « dont N oiseaux » serait redondante.
                if ($nbOiseaux === count($especes)) {
                    $phrase .= $nbOiseaux > 1 ? ', toutes oiseaux' : ', un oiseau';
                } else {
                    $phrase .= ', dont '.$this->nb($nbOiseaux).($nbOiseaux > 1 ? ' oiseaux' : ' oiseau');
                }
            }
            if ($nbPrioritaires > 0) {
                $phrase .= ($nbOiseaux > 0 ? ' et ' : ', dont ').$this->nb($nbPrioritaires)
                    .' prioritaire'.$this->pl($nbPrioritaires);
            }
            $phrases[] = $phrase.'.';
        }

        return implode(' ', $phrases);
    }

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
            // Noms cités : le référentiel INPN contient de l'italique HTML
            // (<i>Otus scops</i>). En liste jointe, on affiche du texte pur.
            $groupes[$e->groupe_taxo ?: 'Autres'][] = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $e->nom_cite)));
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

        $communesAbc = $abcCommunes->map(fn ($code) => [
            'code' => $code,
            'libelle' => $labels[$code] ?? $code,
        ])->values()->all();

        $nbCommunesTotal = $communes->count();

        $groupesPayload = array_map(
            fn (string $g, array $noms) => ['groupe' => $g, 'noms' => $noms, 'n' => count($noms)],
            array_keys($groupes),
            array_values($groupes)
        );

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
            'chapeau' => $this->chapeauZnieff($zone, $communesAbc, $nbCommunesTotal, $groupesPayload),
            'communes' => $communesAbc,
            'nb_communes_total' => $nbCommunesTotal,
            'groupes_especes' => $groupesPayload,
            'parent' => $parent,
            'enfants' => $enfants,
            'versions' => $this->versions(),
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
            ->get(['cd_ue', 'nom', 'prioritaire', 'cd_hab', 'representativite', 'conservation', 'evaluation_globale'])
            ->map(fn ($h) => [
                'cd_ue' => (string) $h->cd_ue,
                'nom' => $h->nom !== null ? (string) $h->nom : null,
                'prioritaire' => (bool) $h->prioritaire,
                'cd_hab' => $h->cd_hab !== null ? (string) $h->cd_hab : null,
                'representativite' => $h->representativite !== null ? (string) $h->representativite : null,
                'conservation' => $h->conservation !== null ? (string) $h->conservation : null,
                'evaluation_globale' => $h->evaluation_globale !== null ? (string) $h->evaluation_globale : null,
            ])
            ->all();

        $especes = DB::table('inpn_natura_especes')
            ->where('sitecode', $sitecode)
            ->orderBy('nom_scientifique')
            ->get(['code_n2000', 'nom_scientifique', 'nom_vernaculaire', 'annexe_ii', 'annexe_1', 'prioritaire', 'presences'])
            ->map(fn ($e) => [
                'code_n2000' => (string) $e->code_n2000,
                'nom_scientifique' => $e->nom_scientifique !== null ? (string) $e->nom_scientifique : null,
                'nom_vernaculaire' => $e->nom_vernaculaire !== null ? (string) $e->nom_vernaculaire : null,
                'annexe_ii' => (bool) $e->annexe_ii,
                'annexe_1' => (bool) $e->annexe_1,
                'prioritaire' => (bool) $e->prioritaire,
                'presences' => $e->presences !== null ? (string) $e->presences : null,
            ])
            ->all();

        // DOCOB : PDF hébergé localement (public/docob/, config surchargeable
        // en test) en priorité, sinon le document référencé dans la base INPN.
        $docobDir = (string) config('abc.inpn.docob_dir');
        $pdf = $docobDir.'/'.$sitecode.'.pdf';
        $docob = DB::table('inpn_natura_docob')
            ->where('sitecode', $sitecode)
            ->orderByDesc('id')
            ->get(['titre', 'lien'])
            ->map(fn ($d) => [
                'titre' => $d->titre !== null ? (string) $d->titre : 'Document d\'objectifs',
                'lien' => $d->lien !== null ? (string) $d->lien : null,
            ])
            ->all();

        $communesAbc = $abcCommunes->map(fn ($code) => [
            'code' => $code,
            'libelle' => $labels[$code] ?? $code,
        ])->values()->all();

        $nbCommunesTotal = $communes->count();

        return [
            'sitecode' => (string) $site->sitecode,
            'nom' => $site->nom !== null ? (string) $site->nom : null,
            'type_site' => $site->type_site !== null ? (string) $site->type_site : null,
            'pdf_local' => is_file($pdf) ? '/docob/'.$sitecode.'.pdf' : null,
            'docob' => $docob,
            'chapeau' => $this->chapeauNatura($site, $communesAbc, $nbCommunesTotal, $habitats, $especes),
            'communes' => $communesAbc,
            'nb_communes_total' => $nbCommunesTotal,
            'habitats' => $habitats,
            'especes' => $especes,
            'versions' => $this->versions(),
        ];
    }

    /**
     * Date de référence de chaque base INPN importée (inpn_imports) :
     * affichée sur les fiches, à côté de l'attribution.
     *
     * @return array<string, string>
     */
    private function versions(): array
    {
        return DB::table('inpn_imports')->pluck('version_source', 'source')->all();
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
