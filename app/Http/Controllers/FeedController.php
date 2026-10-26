<?php

namespace App\Http\Controllers;

use App\Models\Actualite;
use App\Services\MarkdownService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Flux Atom des actualités publiées (/feed.xml).
 */
class FeedController extends Controller
{
    public function __construct(private MarkdownService $markdown) {}

    public function __invoke(): Response
    {
        $xml = Cache::remember('abc:feed', now()->addHours(1), function () {
            $base = rtrim(url('/'), '/');
            $esc = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $actualites = Actualite::query()
                ->where('statut', 'publie')
                ->where(fn ($q) => $q->whereNull('date_publication')->orWhere('date_publication', '<=', now()))
                ->orderByDesc('date_publication')
                ->limit(50)
                ->get(['slug', 'titre', 'contenu', 'date_publication', 'updated_at']);

            $latest = $actualites->first();
            $updatedAtom = $latest
                ? ($latest->date_publication ?? $latest->updated_at ?? now())->toAtomString()
                : now()->toAtomString();

            $entries = $actualites->map(function (Actualite $a) use ($base, $esc) {
                $date = ($a->date_publication ?? $a->updated_at ?? now())->toAtomString();
                $url = $base.'/actualites/'.$a->slug;
                $summary = $this->markdown->toText($a->contenu, 300);

                return "    <entry>\n"
                    ."      <title>{$esc($a->titre)}</title>\n"
                    ."      <link href=\"{$url}\"/>\n"
                    ."      <id>{$url}</id>\n"
                    ."      <updated>{$date}</updated>\n"
                    ."      <summary>{$esc($summary)}</summary>\n"
                    .'    </entry>';
            })->implode("\n");

            $title = $esc((string) config('seo.site_name')).' — Actualités';

            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                .'<feed xmlns="http://www.w3.org/2005/Atom">'."\n"
                ."  <title>{$title}</title>\n"
                ."  <link href=\"{$base}/feed.xml\" rel=\"self\"/>\n"
                ."  <link href=\"{$base}/actualites\" rel=\"alternate\"/>\n"
                ."  <id>{$base}/feed.xml</id>\n"
                ."  <updated>{$updatedAtom}</updated>\n"
                ."  <author>\n"
                .'    <name>'.$esc((string) config('seo.site_name'))."</name>\n"
                ."  </author>\n"
                ."\n{$entries}\n"
                ."</feed>\n";
        });

        return response($xml, 200, [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
