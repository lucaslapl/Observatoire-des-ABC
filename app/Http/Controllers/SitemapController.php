<?php

namespace App\Http\Controllers;

use App\Services\RegionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function __invoke(RegionService $regions): Response
    {
        $xml = Cache::remember('abc:sitemap', now()->addHours(12), function () use ($regions) {
            $base = rtrim(url('/'), '/');
            $urls = [];

            $urls[] = $this->entry($base.'/', null, '1.0', 'daily');
            $urls[] = $this->entry($base.'/carte', null, '0.9', 'weekly');
            $urls[] = $this->entry($base.'/actualites', null, '0.6', 'weekly');
            $urls[] = $this->entry($base.'/contact', null, '0.5', 'monthly');
            $urls[] = $this->entry($base.'/a-propos', null, '0.3', 'yearly');
            $urls[] = $this->entry($base.'/methodologie', null, '0.5', 'yearly');
            $urls[] = $this->entry($base.'/mentions-legales', null, '0.3', 'yearly');
            $urls[] = $this->entry($base.'/confidentialite', null, '0.3', 'yearly');
            // Page « API & réutilisation » et documentation publique.
            $urls[] = $this->entry($base.'/api', null, '0.5', 'yearly');
            $urls[] = $this->entry($base.'/api/doc', null, '0.5', 'yearly');

            // Actualités publiées.
            foreach (DB::table('actualites')
                ->where('statut', 'publie')
                ->where(fn ($q) => $q->whereNull('date_publication')->orWhere('date_publication', '<=', now()))
                ->orderBy('date_publication', 'desc')
                ->get(['slug', 'date_publication']) as $a) {
                $urls[] = $this->entry($base.'/actualites/'.$a->slug, $a->date_publication, '0.5', 'monthly');
            }

            // Fiches projets.
            foreach (DB::table('projets')->whereNotNull('slug')
                ->get(['slug', 'statut_maj_at', 'updated_at']) as $p) {
                $lastmod = $p->statut_maj_at ?? $p->updated_at;
                $urls[] = $this->entry($base.'/abc/'.$p->slug, $lastmod, '0.8', 'weekly');
            }

            // Communes concernées.
            foreach (DB::table('communes')->where('anomalie', false)
                ->select('code_geographique')->distinct()->get() as $c) {
                $urls[] = $this->entry($base.'/commune/'.$c->code_geographique, null, '0.6', 'monthly');
            }

            // Départements (codes normalisés).
            foreach (DB::table('communes')->where('anomalie', false)
                ->whereNotNull('departement')->distinct()->get(['departement']) as $d) {
                $code = mb_strtolower((string) $d->departement);
                $urls[] = $this->entry($base.'/departement/'.$code, null, '0.7', 'weekly');
            }

            // Régions.
            foreach (array_keys($regions->slugsIndex()) as $slug) {
                $urls[] = $this->entry($base.'/region/'.$slug, null, '0.7', 'weekly');
            }

            // Patrimoine écologique INPN : pages hub + fiches éligibles
            // (zonages touchant au moins une commune ABC).
            $urls[] = $this->entry($base.'/patrimoine', null, '0.4', 'monthly');
            $urls[] = $this->entry($base.'/patrimoine/znieff', null, '0.4', 'weekly');
            $urls[] = $this->entry($base.'/patrimoine/natura', null, '0.4', 'weekly');

            $codesAbc = DB::table('communes')->distinct()->pluck('code_geographique');
            foreach (DB::table('inpn_znieff as z')
                ->join('inpn_znieff_communes as zc', 'zc.znieff_id', '=', 'z.id')
                ->whereIn('zc.code_commune', $codesAbc)
                ->groupBy('z.id', 'z.id_national')
                ->orderBy('z.id_national')
                ->get(['z.id_national']) as $z) {
                $urls[] = $this->entry($base.'/patrimoine/znieff/'.$z->id_national, null, '0.4', 'monthly');
            }
            foreach (DB::table('inpn_natura_sites as s')
                ->join('inpn_natura_communes as nc', 'nc.sitecode', '=', 's.sitecode')
                ->whereIn('nc.code_commune', $codesAbc)
                ->groupBy('s.sitecode')
                ->orderBy('s.sitecode')
                ->get(['s.sitecode']) as $n) {
                $urls[] = $this->entry($base.'/patrimoine/natura/'.$n->sitecode, null, '0.4', 'monthly');
            }

            // Pages « patrimoine du périmètre » des fiches ABC : une URL par
            // projet dont au moins une commune (non écartée) touche un zonage
            // ou une aire des référentiels INPN.
            $codesInpn = DB::table('inpn_znieff_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune')
                ->merge(DB::table('inpn_natura_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune'))
                ->merge(DB::table('inpn_aires_protegees_communes')->whereIn('code_commune', $codesAbc)->distinct()->pluck('code_commune'))
                ->unique();
            foreach (DB::table('projets')
                ->whereNotNull('slug')
                ->whereIn('id', fn ($q) => $q->select('projet_id')
                    ->from('communes')
                    ->whereIn('code_geographique', $codesInpn)
                    ->where('anomalie', false))
                ->orderBy('slug')
                ->pluck('slug') as $slug) {
                $urls[] = $this->entry($base.'/abc/'.$slug.'/patrimoine', null, '0.4', 'monthly');
            }

            $lines = implode("\n", $urls);

            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
                .$lines
                ."\n</urlset>\n";
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
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
