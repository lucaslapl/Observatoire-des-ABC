<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sitemap.xml : construction des sections d'URLs publiques, rendu XML et
 * cache applicatif versionné.
 *
 * La clé de cache est versionnée (abc:sitemap:v2) : une entrée héritée
 * d'une ancienne version du générateur — notamment une entrée sans
 * expiration qui ne serait jamais partie — est immédiatement ignorée,
 * sans dépendre d'un TTL (audit SEO du 2026-11-17 : sitemap de production
 * figé à 179 URLs alors que le générateur en produit plusieurs milliers).
 *
 * La commande abc:sitemap:flush (et le bouton « Régénérer le sitemap » du
 * panneau d'administration, pour les hébergements sans shell) oublie toutes
 * les clés connues puis réchauffe la nouvelle.
 */
class SitemapService
{
    /** Clé de cache courante : la décaler d'une version invalide tout l'historique. */
    public const CACHE_KEY = 'abc:sitemap:v2';

    /** Clés des versions antérieures : jamais lues, oubliées par le flush. */
    public const LEGACY_CACHE_KEYS = ['abc:sitemap'];

    /** Durée de vie d'une entrée de cache (heures). */
    public const CACHE_TTL_HOURS = 12;

    public function __construct(
        private DepartementService $departements,
        private RegionService $regions,
    ) {}

    /**
     * XML complet servi par /sitemap.xml, depuis le cache applicatif.
     */
    public function cached(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHours(self::CACHE_TTL_HOURS),
            fn (): string => $this->render($this->sections()),
        );
    }

    /**
     * Oublie toutes les clés abc:sitemap* (y compris les versions
     * antérieures), régénère le sitemap et réchauffe le cache : la
     * prochaine requête /sitemap.xml sert le sitemap neuf sans le
     * reconstruire.
     *
     * @return array{total: int, sections: array<string, int>} nombre total d'URLs et répartition par section
     */
    public function flush(): array
    {
        foreach ([self::CACHE_KEY, ...self::LEGACY_CACHE_KEYS] as $key) {
            Cache::forget($key);
        }

        $sections = $this->sections();

        Cache::put(self::CACHE_KEY, $this->render($sections), now()->addHours(self::CACHE_TTL_HOURS));

        $counts = array_map('count', $sections);

        return [
            'total' => array_sum($counts),
            'sections' => $counts,
        ];
    }

    /**
     * Sections d'URLs du sitemap, dans l'ordre d'émission du XML.
     *
     * @return array<string, array<int, string>> entrées <url> complètes par section
     */
    public function sections(): array
    {
        $base = rtrim(url('/'), '/');

        // Fiches projets.
        $abc = [];
        foreach (DB::table('projets')->whereNotNull('slug')
            ->get(['slug', 'statut_maj_at', 'updated_at']) as $p) {
            $lastmod = $p->statut_maj_at ?? $p->updated_at;
            $abc[] = $this->entry($base.'/abc/'.$p->slug, $lastmod, '0.8', 'weekly');
        }

        // Pages « patrimoine du périmètre » des fiches ABC : une URL par
        // projet dont au moins une commune (non écartée) touche un zonage
        // ou une aire des référentiels INPN.
        $codesAbc = DB::table('communes')->distinct()->pluck('code_geographique');
        $codesInpn = DB::table('inpn_znieff_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune')
            ->merge(DB::table('inpn_natura_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune'))
            ->merge(DB::table('inpn_aires_protegees_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune'))
            ->unique();
        $abcPatrimoine = [];
        foreach (DB::table('projets')
            ->whereNotNull('slug')
            ->whereIn('id', fn ($q) => $q->select('projet_id')
                ->from('communes')
                ->whereIn('code_geographique', $codesInpn)
                ->where('anomalie', false))
            ->orderBy('slug')
            ->pluck('slug') as $slug) {
            $abcPatrimoine[] = $this->entry($base.'/abc/'.$slug.'/patrimoine', null, '0.4', 'monthly');
        }

        return [
            // Pages statiques, actualités publiées, fiches, pages
            // territoriales : mêmes poids et fréquences que la carte.
            'statiques' => $this->statiques($base),
            'actualites' => $this->actualites($base),
            'abc' => $abc,
            'communes' => $this->communes($base),
            'departements' => $this->departements($base),
            'regions' => $this->regions($base),
            // Patrimoine écologique INPN : pages hub (toujours présentes)
            // puis fiches éligibles (zonages touchant au moins une commune
            // ABC).
            'patrimoine' => [
                $this->entry($base.'/patrimoine', null, '0.4', 'monthly'),
                $this->entry($base.'/patrimoine/znieff', null, '0.4', 'weekly'),
                $this->entry($base.'/patrimoine/natura', null, '0.4', 'weekly'),
            ],
            'znieff' => $this->znieff($base, $codesAbc),
            'natura' => $this->natura($base, $codesAbc),
            'abc_patrimoine' => $abcPatrimoine,
        ];
    }

    public function render(array $sections): string
    {
        $lines = [];
        foreach ($sections as $entries) {
            foreach ($entries as $entry) {
                $lines[] = $entry;
            }
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .implode("\n", $lines)
            ."\n</urlset>\n";
    }

    /**
     * @return array<int, string>
     */
    private function statiques(string $base): array
    {
        return [
            $this->entry($base.'/', null, '1.0', 'daily'),
            $this->entry($base.'/carte', null, '0.9', 'weekly'),
            $this->entry($base.'/abc', null, '0.9', 'weekly'),
            // Worklist publique de vérification : indexée (transparence de
            // la méthode), maintenue hors du Disallow de robots.txt.
            $this->entry($base.'/verify', null, '0.4', 'daily'),
            $this->entry($base.'/actualites', null, '0.6', 'weekly'),
            $this->entry($base.'/contact', null, '0.5', 'monthly'),
            $this->entry($base.'/a-propos', null, '0.3', 'yearly'),
            $this->entry($base.'/methodologie', null, '0.5', 'yearly'),
            $this->entry($base.'/faq', null, '0.5', 'yearly'),
            $this->entry($base.'/mentions-legales', null, '0.3', 'yearly'),
            $this->entry($base.'/confidentialite', null, '0.3', 'yearly'),
            // Page « API & réutilisation » et documentation publique.
            $this->entry($base.'/api', null, '0.5', 'yearly'),
            $this->entry($base.'/api/doc', null, '0.5', 'yearly'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function actualites(string $base): array
    {
        $entries = [];
        foreach (DB::table('actualites')
            ->where('statut', 'publie')
            ->where(fn ($q) => $q->whereNull('date_publication')->orWhere('date_publication', '<=', now()))
            ->orderBy('date_publication', 'desc')
            ->get(['slug', 'date_publication']) as $a) {
            $entries[] = $this->entry($base.'/actualites/'.$a->slug, $a->date_publication, '0.5', 'monthly');
        }

        return $entries;
    }

    /**
     * Communes concernées (hors anomalies).
     *
     * @return array<int, string>
     */
    private function communes(string $base): array
    {
        $entries = [];
        foreach (DB::table('communes')->where('anomalie', false)
            ->select('code_geographique')->distinct()->get() as $c) {
            $entries[] = $this->entry($base.'/commune/'.$c->code_geographique, null, '0.6', 'monthly');
        }

        return $entries;
    }

    /**
     * Départements : codes normalisés, y compris ceux dont les lignes
     * communes n'ont pas de colonne `departement` (le code est alors dérivé
     * du préfixe INSEE).
     *
     * @return array<int, string>
     */
    private function departements(string $base): array
    {
        $entries = [];
        foreach ($this->departements->codesPresents() as $code) {
            $entries[] = $this->entry($base.'/departement/'.$code, null, '0.7', 'weekly');
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function regions(string $base): array
    {
        $entries = [];
        foreach (array_keys($this->regions->slugsIndex()) as $slug) {
            $entries[] = $this->entry($base.'/region/'.$slug, null, '0.7', 'weekly');
        }

        return $entries;
    }

    /**
     * Fiches ZNIEFF éligibles (touchant au moins une commune ABC).
     *
     * @param  Collection<int, string>  $codesAbc
     * @return array<int, string>
     */
    private function znieff(string $base, $codesAbc): array
    {
        $entries = [];
        foreach (DB::table('inpn_znieff as z')
            ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
            ->whereIn('zc.code_commune', $codesAbc)
            ->groupBy('z.id', 'z.id_national')
            ->orderBy('z.id_national')
            ->get(['z.id_national']) as $z) {
            $entries[] = $this->entry($base.'/patrimoine/znieff/'.$z->id_national, null, '0.4', 'monthly');
        }

        return $entries;
    }

    /**
     * Fiches Natura 2000 éligibles (touchant au moins une commune ABC).
     *
     * @param  Collection<int, string>  $codesAbc
     * @return array<int, string>
     */
    private function natura(string $base, $codesAbc): array
    {
        $entries = [];
        foreach (DB::table('inpn_natura_sites as s')
            ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
            ->whereIn('nc.code_commune', $codesAbc)
            ->groupBy('s.sitecode')
            ->orderBy('s.sitecode')
            ->get(['s.sitecode']) as $n) {
            $entries[] = $this->entry($base.'/patrimoine/natura/'.$n->sitecode, null, '0.4', 'monthly');
        }

        return $entries;
    }

    private function entry(string $loc, ?string $lastmod = null, string $priority = '0.5', string $changefreq = 'monthly'): string
    {
        $esc = htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $lines = ['  <url>'];
        $lines[] = '    <loc>'.$esc.'</loc>';
        if ($lastmod) {
            $lines[] = '    <lastmod>'.Str::substr((string) $lastmod, 0, 10).'</lastmod>';
        }
        $lines[] = '    <changefreq>'.$changefreq.'</changefreq>';
        $lines[] = '    <priority>'.$priority.'</priority>';
        $lines[] = '  </url>';

        return implode("\n", $lines);
    }
}
