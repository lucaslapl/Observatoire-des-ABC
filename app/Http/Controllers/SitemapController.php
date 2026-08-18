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
            $urls[] = $this->entry($base.'/actualites', null, '0.6', 'weekly');

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

            $lines = implode("\n", $urls);

            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
                .$lines
                ."\n</urlset>\n";
        });

        return response($xml)->header('Content-Type', 'application/xml');
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
