<?php

namespace App\Services;

use App\Models\Projet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liste publique « tous les ABC » (/abc) : l'ensemble des projets, passés,
 * présents et à venir, filtrable et triable sur la démographie du périmètre
 * (population, superficie, densité), le patrimoine écologique INPN (aires
 * protégées, ZNIEFF, Natura 2000, espèces et habitats) et les attributs du
 * projet (statut, année, territoire, source).
 *
 * Toute la liste est calculée côté serveur en SQL (une seule requête par
 * page) : les URL avec query string restent partageables et indexables.
 * Le statut affiché est le statut vérifié quand il existe (le verdict de
 * vérification prime sur le statut calculé, comme sur la carte et les
 * fiches). Les communes « anomalie » du périmètre sont écartées de tous
 * les comptages, comme pour la démographie des fiches.
 */
class ProjetsListeService
{
    private const PAR_PAGE = 50;

    public const TRIS = ['nom', 'statut', 'annee', 'communes', 'population', 'superficie', 'densite', 'aires'];

    public const STATUTS = ['en_cours', 'a_venir', 'termine', 'inconnu'];

    /* Statut affiché : verdict de vérification prioritaire. */
    private const SQL_STATUT_AFFICHE = 'COALESCE(CASE verifications.etat'
        ." WHEN 'confirme_termine' THEN 'termine'"
        ." WHEN 'confirme_en_cours' THEN 'en_cours'"
        ." WHEN 'toujours_a_venir' THEN 'a_venir'"
        .' END, projets.statut)';

    /* Agrégats du périmètre (communes « anomalie » écartées ; les communes
       non géocodées n'ont ni population ni superficie : les sommes ne
       portent que sur les lignes renseignées). */
    private const SQL_NB_COMMUNES = '(SELECT COUNT(*) FROM communes c WHERE c.projet_id = projets.id AND c.anomalie = FALSE)';

    private const SQL_POPULATION = '(SELECT COALESCE(SUM(c.population), 0) FROM communes c WHERE c.projet_id = projets.id AND c.anomalie = FALSE)';

    private const SQL_SUPERFICIE = '(SELECT COALESCE(SUM(c.surface_km2), 0) FROM communes c WHERE c.projet_id = projets.id AND c.anomalie = FALSE AND c.surface_km2 IS NOT NULL)';

    private const SQL_DENSITE = '(CASE WHEN '.self::SQL_SUPERFICIE.' > 0'
        .' THEN ('.self::SQL_POPULATION.' * 1.0) / '.self::SQL_SUPERFICIE
        .' END)';

    private const SQL_NB_AIRES = '(SELECT COUNT(DISTINCT apc.aire_protegee_id)'
        .' FROM inpn_aires_protegees_communes apc'
        .' JOIN communes c ON c.code_geographique = apc.code_commune'
        .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE)';

    private const SQL_NB_ZNIEFF = '(SELECT COUNT(DISTINCT zc.znieff_id)'
        .' FROM inpn_znieff_communes zc'
        .' JOIN communes c ON c.code_geographique = zc.code_commune'
        .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE)';

    private const SQL_NB_NATURA = '(SELECT COUNT(DISTINCT nc.sitecode)'
        .' FROM inpn_natura_communes nc'
        .' JOIN communes c ON c.code_geographique = nc.code_commune'
        .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE)';

    public function __construct(
        private RegionService $regions,
        private DepartementService $departements,
        private NomService $noms,
        private StatusService $status,
    ) {}

    /**
     * Page courante de la liste, à partir de la query string brute.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function liste(array $query): array
    {
        $params = $this->parametres($query);

        $total = (clone $this->requete($params))->toBase()->count();
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = min(max(1, $params['page']), $pages);

        $rows = $this->requete($params)
            ->orderByRaw($this->triSql($params['tri']).' '.($params['sens'] === 'desc' ? 'DESC' : 'ASC').$this->nullsLast($params['tri']))
            ->orderByRaw('LOWER(projets.nom)')
            ->forPage($page, self::PAR_PAGE)
            ->get()
            ->all();

        return [
            'items' => $this->items($rows),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'par_page' => self::PAR_PAGE,
            'query' => array_merge($params, ['page' => $page]),
            'filtres' => $this->facettes(),
        ];
    }

    /**
     * Requête de base : projets + statut affiché + agrégats du périmètre,
     * avec les filtres demandés appliqués.
     *
     * @param  array<string, mixed>  $params
     * @return Builder<Projet>
     */
    private function requete(array $params): Builder
    {
        $q = Projet::query()
            ->leftJoin('verifications', 'verifications.projet_id', '=', 'projets.id')
            ->select([
                'projets.id', 'projets.nom', 'projets.slug', 'projets.structure_porteuse',
                'projets.annee_debut', 'projets.annee_fin', 'projets.statut',
                'projets.source', 'projets.ami_ofb',
                'verifications.etat AS verif_etat',
            ])
            ->selectRaw(self::SQL_STATUT_AFFICHE.' AS statut_affiche')
            ->selectRaw(self::SQL_NB_COMMUNES.' AS nb_communes')
            ->selectRaw(self::SQL_POPULATION.' AS population')
            ->selectRaw(self::SQL_SUPERFICIE.' AS superficie')
            ->selectRaw(self::SQL_DENSITE.' AS densite')
            ->selectRaw(self::SQL_NB_AIRES.' AS nb_aires')
            ->selectRaw(self::SQL_NB_ZNIEFF.' AS nb_znieff')
            ->selectRaw(self::SQL_NB_NATURA.' AS nb_natura')
            ->selectRaw('(SELECT c.libelle_departement FROM communes c'
                .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE'
                .' AND c.libelle_departement IS NOT NULL LIMIT 1) AS departement_label')
            ->selectRaw('(SELECT c.departement FROM communes c'
                .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE'
                .' AND c.departement IS NOT NULL LIMIT 1) AS departement_code')
            ->selectRaw('(SELECT c.region FROM communes c'
                .' WHERE c.projet_id = projets.id AND c.anomalie = FALSE'
                .' AND c.region IS NOT NULL LIMIT 1) AS region_raw');

        if ($params['q'] !== null) {
            $motif = $this->motif($params['q']);
            $q->where(function ($w) use ($motif) {
                $w->whereRaw('LOWER(projets.nom) LIKE ?', [$motif])
                    ->orWhereRaw("LOWER(COALESCE(projets.structure_porteuse, '')) LIKE ?", [$motif])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM communes c WHERE c.projet_id = projets.id'
                        .' AND (LOWER(c.libelle_geographique) LIKE ? OR c.code_geographique LIKE ?))', [$motif, $motif]);
            });
        }

        if ($params['statut'] !== null) {
            $q->whereRaw(self::SQL_STATUT_AFFICHE.' = ?', [$params['statut']]);
        }

        if ($params['region'] !== null) {
            $valeurs = $this->valeursRegion($params['region']);
            if ($valeurs === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereRaw('EXISTS (SELECT 1 FROM communes c WHERE c.projet_id = projets.id'
                    .' AND c.anomalie = FALSE AND c.region IN ('.implode(', ', array_fill(0, count($valeurs), '?')).'))', $valeurs);
            }
        }

        if ($params['departement'] !== null) {
            // Colonne `departement` ou, à défaut, préfixe du code INSEE :
            // certaines lignes (ex. millésimes récents) n'ont pas la
            // colonne renseignée mais appartiennent bien au département.
            // Préfixe sur 3 caractères uniquement pour l'outre-mer (97x/98x).
            $norm = Str::lower($params['departement']);
            $len = preg_match('/^9[78]/', $norm) === 1 ? 3 : 2;
            $q->whereRaw('EXISTS (SELECT 1 FROM communes c WHERE c.projet_id = projets.id'
                .' AND c.anomalie = FALSE AND (LOWER(COALESCE(c.departement, \'\')) = ?'
                ." OR LOWER(SUBSTR(c.code_geographique, 1, {$len})) = ?))", [$norm, $norm]);
        }

        if ($params['source'] !== null) {
            $q->where('projets.source', $params['source']);
        }

        if ($params['ami_ofb'] !== null) {
            $q->where('projets.ami_ofb', $params['ami_ofb']);
        }

        if ($params['annee_min'] !== null || $params['annee_max'] !== null) {
            $q->where(function ($w) use ($params) {
                if ($params['annee_min'] !== null) {
                    $w->where('projets.annee_debut', '>=', $params['annee_min']);
                }
                if ($params['annee_max'] !== null) {
                    $w->where('projets.annee_debut', '<=', $params['annee_max']);
                }
            });
        }

        if ($params['pop_min'] !== null) {
            $q->whereRaw(self::SQL_POPULATION.' >= ?', [$params['pop_min']]);
        }
        if ($params['pop_max'] !== null) {
            $q->whereRaw(self::SQL_POPULATION.' <= ?', [$params['pop_max']]);
        }
        if ($params['surf_min'] !== null) {
            // CAST(... AS REAL) : en SQLite, une binding texte comparée à un
            // résultat numérique est toujours « supérieure » (ordre TEXT >
            // REAL) et le filtre ne matcherait jamais ; le CAST force la
            // comparaison numérique (valide aussi en PostgreSQL).
            $q->whereRaw(self::SQL_SUPERFICIE.' >= CAST(? AS REAL)', [$params['surf_min']]);
        }
        if ($params['densite_min'] !== null) {
            $q->whereRaw(self::SQL_DENSITE.' >= CAST(? AS REAL)', [$params['densite_min']]);
        }
        if ($params['patrimoine'] !== null) {
            $sql = match ($params['patrimoine']) {
                'natura' => self::SQL_NB_NATURA,
                'znieff' => self::SQL_NB_ZNIEFF,
                default => self::SQL_NB_AIRES,
            };
            $q->whereRaw($sql.' > 0');
        }

        if ($params['espece'] !== null) {
            $ids = $this->idsEspece($params['espece']);
            $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('projets.id', $ids);
        }

        if ($params['habitat'] !== null) {
            $ids = $this->idsHabitat($params['habitat']);
            $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('projets.id', $ids);
        }

        return $q;
    }

    /**
     * Projets dont une commune du périmètre (non écartée) touche un zonage
     * INPN citant l'espèce : ZNIEFF (nom cité) ou Natura 2000 (espèce
     * directive). Un critère numérique pur est comparé au cd_ref TAXREF.
     *
     * Résolu en deux requêtes préalables plutôt qu'un EXISTS corrélé :
     * le scan LIKE du référentiel ne s'exécute qu'une fois, pas une fois
     * par projet.
     *
     * @return array<int, string>
     */
    private function idsEspece(string $critere): array
    {
        $motif = $this->motif($critere);
        $code = ctype_digit($critere) ? $critere : null;

        $znieff = DB::table('communes as c')
            ->where('c.anomalie', false)
            ->whereExists(function ($w) use ($motif, $code) {
                $w->selectRaw('1')
                    ->from('inpn_znieff_communes as zc')
                    ->join('inpn_znieff_especes as ze', 'ze.znieff_id', '=', 'zc.znieff_id')
                    ->whereColumn('zc.code_commune', 'c.code_geographique');
                $w->where(function ($w2) use ($motif, $code) {
                    $w2->whereRaw('LOWER(ze.nom_cite) LIKE ?', [$motif]);
                    if ($code !== null) {
                        $w2->orWhere('ze.cd_ref', (int) $code);
                    }
                });
            })
            ->distinct()
            ->pluck('c.projet_id');

        $natura = DB::table('communes as c')
            ->where('c.anomalie', false)
            ->whereExists(function ($w) use ($motif, $code) {
                $w->selectRaw('1')
                    ->from('inpn_natura_communes as nc')
                    ->join('inpn_natura_especes as ne', 'ne.sitecode', '=', 'nc.sitecode')
                    ->whereColumn('nc.code_commune', 'c.code_geographique');
                $w->where(function ($w2) use ($motif, $code) {
                    $w2->whereRaw('LOWER(ne.nom_scientifique) LIKE ?', [$motif])
                        ->orWhereRaw("LOWER(COALESCE(ne.nom_vernaculaire, '')) LIKE ?", [$motif]);
                    if ($code !== null) {
                        $w2->orWhere('ne.cd_ref', (int) $code);
                    }
                });
            })
            ->distinct()
            ->pluck('c.projet_id');

        return $znieff->merge($natura)->unique()->values()->all();
    }

    /**
     * Projets dont une commune du périmètre (non écartée) touche un site
     * Natura 2000 dont un habitat correspond au code demandé (cd_ue, ex.
     * 2110).
     *
     * @return array<int, string>
     */
    private function idsHabitat(string $critere): array
    {
        $sitecodes = DB::table('inpn_natura_habitats')
            ->where('cd_ue', 'LIKE', $this->motif($critere))
            ->distinct()
            ->pluck('sitecode')
            ->all();

        if ($sitecodes === []) {
            return [];
        }

        return DB::table('communes as c')
            ->join('inpn_natura_communes as nc', 'nc.code_commune', '=', 'c.code_geographique')
            ->where('c.anomalie', false)
            ->whereIn('nc.sitecode', $sitecodes)
            ->distinct()
            ->pluck('c.projet_id')
            ->all();
    }

    /**
     * Normalisation de la query string : valeurs par défaut, bornes et
     * listes blanches (tri, statut). Renvoie null pour tout paramètre vide
     * ou invalide.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function parametres(array $query): array
    {
        $texte = fn (string $cle, int $max = 100) => isset($query[$cle]) && is_scalar($query[$cle]) && trim((string) $query[$cle]) !== ''
            ? Str::limit(trim((string) $query[$cle]), $max, '')
            : null;

        $entier = function (string $cle, ?int $min, ?int $max) use ($query): ?int {
            if (! isset($query[$cle]) || ! is_scalar($query[$cle]) || ! ctype_digit(ltrim(trim((string) $query[$cle]), '-'))) {
                return null;
            }
            $v = (int) $query[$cle];
            if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                return null;
            }

            return $v;
        };

        $tri = isset($query['tri']) && is_string($query['tri']) && in_array($query['tri'], self::TRIS, true)
            ? $query['tri'] : 'nom';

        return [
            'q' => $texte('q'),
            'statut' => isset($query['statut']) && in_array($query['statut'], self::STATUTS, true) ? $query['statut'] : null,
            'region' => $texte('region', 100),
            'departement' => $texte('departement', 10),
            'source' => $texte('source', 50),
            'ami_ofb' => isset($query['ami_ofb']) && in_array($query['ami_ofb'], ['0', '1', 0, 1], true)
                ? (bool) $query['ami_ofb'] : null,
            'annee_min' => $entier('annee_min', 1900, 2200),
            'annee_max' => $entier('annee_max', 1900, 2200),
            'pop_min' => $entier('pop_min', 0, null),
            'pop_max' => $entier('pop_max', 0, null),
            'surf_min' => isset($query['surf_min']) && is_numeric($query['surf_min']) && (float) $query['surf_min'] >= 0
                ? (float) $query['surf_min'] : null,
            'densite_min' => isset($query['densite_min']) && is_numeric($query['densite_min']) && (float) $query['densite_min'] >= 0
                ? (float) $query['densite_min'] : null,
            'patrimoine' => isset($query['patrimoine']) && in_array($query['patrimoine'], ['znieff', 'natura', 'aires'], true)
                ? $query['patrimoine'] : null,
            'espece' => $texte('espece'),
            'habitat' => $texte('habitat', 20),
            'tri' => $tri,
            'sens' => isset($query['sens']) && $query['sens'] === 'desc' ? 'desc' : 'asc',
            'page' => isset($query['page']) && ctype_digit((string) $query['page']) ? max(1, (int) $query['page']) : 1,
        ];
    }

    /**
     * Motif LIKE pour une saisie libre : mise en minuscules et retrait des
     * jokers (%, _, antislash) — pas de clause ESCAPE, dont la syntaxe
     * pose des problèmes au parseur de placeholders PDO pgsql.
     */
    private function motif(string $texte): string
    {
        return '%'.Str::lower(preg_replace('/[%_\\\\]/u', ' ', $texte)).'%';
    }

    /**
     * Valeurs brutes de communes.region correspondant au slug demandé
     * (le libellé canonique, les codes région de la configuration, et les
     * variantes brutes stockées en base — accents ou casse perdus par la
     * source, ex. « Auvergne-Rhone-Alpes »).
     *
     * @return array<int, string>
     */
    private function valeursRegion(string $slug): array
    {
        $label = $this->regions->resolveBySlug($slug);
        if ($label === null) {
            return [];
        }

        $valeurs = [$label];
        foreach (config('regions') as $code => $libelle) {
            if ($libelle === $label) {
                $valeurs[] = $code;
            }
        }
        foreach (DB::table('communes')->whereNotNull('region')->distinct()->pluck('region') as $value) {
            if ($this->regions->normalizeToLabel($value) === $label && ! in_array($value, $valeurs, true)) {
                $valeurs[] = $value;
            }
        }

        return $valeurs;
    }

    /**
     * Valeurs des facettes pour peupler le formulaire (régions, sources,
     * départements, statuts).
     *
     * @return array<string, mixed>
     */
    private function facettes(): array
    {
        $sources = Projet::query()->distinct()->orderBy('source')->pluck('source')
            ->mapWithKeys(fn (string $s) => [$s => LandingService::SOURCES[$s] ?? $s])
            ->all();

        // Une seule entrée par code, libellé canonique du référentiel :
        // les libellés des sources varient (« Rhône » / « Rhone ») et
        // créaient des doublons dans le filtre. Les codes couvrent aussi
        // les lignes sans colonne `departement` (préfixe INSEE).
        $libelles = DB::table('communes')
            ->where('anomalie', false)
            ->whereNotNull('departement')
            ->groupBy('departement')
            ->selectRaw('LOWER(departement) AS code, MAX(libelle_departement) AS libelle')
            ->get()
            ->keyBy('code');

        $departements = array_map(
            fn (string $code) => [
                'code' => $code,
                'label' => $this->departements->label($code)
                    ?? ($libelles->get($code)->libelle ?? mb_strtoupper($code)),
            ],
            $this->departements->codesPresents()
        );

        return [
            'statuts' => array_map(
                fn (string $s) => ['valeur' => $s, 'label' => $this->status->statutLabel($s)],
                self::STATUTS
            ),
            'regions' => $this->regions->slugsIndex(),
            'sources' => $sources,
            'departements' => $departements,
        ];
    }

    /**
     * Lignes de la page → tableaux pour le front.
     *
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function items(array $rows): array
    {
        $ids = array_map(fn ($r) => $r->id, $rows);

        $communes = $ids === [] ? collect()
            : DB::table('communes')
                ->whereIn('projet_id', $ids)
                ->where('anomalie', false)
                ->whereNotNull('libelle_geographique')
                ->orderBy('libelle_geographique')
                ->get(['projet_id', 'code_geographique', 'libelle_geographique'])
                ->groupBy('projet_id');

        $items = [];
        foreach ($rows as $r) {
            $noms = $communes->get($r->id, collect())->take(3)
                ->map(fn ($c) => ['code' => $c->code_geographique, 'libelle' => $c->libelle_geographique])
                ->values()
                ->all();
            $statutVerifie = in_array($r->verif_etat, ['confirme_termine', 'confirme_en_cours', 'toujours_a_venir'], true);

            $items[] = [
                'id' => $r->id,
                'nom' => $this->noms->projet($r->nom, $r->structure_porteuse),
                'slug' => $r->slug,
                'structure_porteuse' => $this->noms->structurePorteuse($r->structure_porteuse),
                'statut' => $r->statut_affiche,
                'statut_label' => $this->status->statutLabel((string) $r->statut_affiche),
                'statut_verifie' => $statutVerifie,
                'annee_debut' => $r->annee_debut,
                'annee_fin' => $r->annee_fin,
                'source' => $r->source,
                'source_label' => LandingService::SOURCES[$r->source] ?? $r->source,
                'ami_ofb' => (bool) $r->ami_ofb,
                'nb_communes' => (int) $r->nb_communes,
                'population' => (int) $r->population,
                'superficie_km2' => $r->superficie > 0 ? round((float) $r->superficie, 1) : null,
                'densite' => $r->densite !== null ? round((float) $r->densite, 1) : null,
                'nb_aires' => (int) $r->nb_aires,
                'nb_znieff' => (int) $r->nb_znieff,
                'nb_natura' => (int) $r->nb_natura,
                'departement_label' => $this->departements->label($r->departement_code) ?: $r->departement_label,
                'region_label' => $this->regions->label($r->region_raw),
                'communes' => $noms,
                'nb_communes_suivantes' => max(0, (int) $r->nb_communes - count($noms)),
            ];
        }

        return $items;
    }

    /* Expression SQL de tri pour ?tri=. Les alias du select (population,
       superficie, densite, nb_communes, nb_aires) sont réutilisables dans
       un ORDER BY, PostgreSQL comme SQLite l'acceptent. */
    private function triSql(string $tri): string
    {
        return match ($tri) {
            'statut' => 'CASE '.self::SQL_STATUT_AFFICHE
                ." WHEN 'en_cours' THEN 0 WHEN 'a_venir' THEN 1 WHEN 'va_debuter' THEN 1"
                ." WHEN 'termine' THEN 2 ELSE 3 END",
            'annee' => 'projets.annee_debut',
            'communes' => 'nb_communes',
            'population' => 'population',
            'superficie' => 'superficie',
            'densite' => 'densite',
            'aires' => 'nb_aires',
            default => 'LOWER(projets.nom)',
        };
    }

    /* Colonnes pouvant être NULL : les lignes sans donnée restent en fin de
       liste quel que soit le sens (NULLS LAST, supporté par PostgreSQL et
       SQLite >= 3.30). */
    private function nullsLast(string $tri): string
    {
        return in_array($tri, ['annee', 'densite'], true) ? ' NULLS LAST' : '';
    }
}
