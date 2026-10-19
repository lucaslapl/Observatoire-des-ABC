<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Téléchargement de fichiers CSV avec cache local (équivalent de
 * `downloadToCache` côté Node).
 */
class DownloadService
{
    public function downloadToCache(string $url, string $label): string
    {
        $dir = config('abc.cache_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $base = basename(parse_url($url, PHP_URL_PATH) ?: $label);
        $dest = $dir.DIRECTORY_SEPARATOR.$base;

        if (file_exists($dest) && filesize($dest) > 0) {
            // Un fichier empoisonné (page HTML d'erreur mise en cache par une
            // version antérieure) ne doit jamais être réutilisé en silence.
            $this->assertCsv((string) file_get_contents($dest), $label, $url);

            return $dest;
        }

        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => config('abc.user_agent'),
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} pour {$url}");
        }

        $body = $response->body();
        $this->assertCsv($body, $label, $url);

        $tmp = $dest.'.part';
        file_put_contents($tmp, $body);
        rename($tmp, $dest);
        usleep(config('abc.request_delay_ms') * 1000);

        return $dest;
    }

    /**
     * Une source peut répondre 200 avec une page HTML (erreur, redirection,
     * rate-limit de archive.org…) au lieu du CSV. Ne jamais la mettre en
     * cache : elle serait réutilisée pour toujours.
     */
    private function assertCsv(string $body, string $label, string $url): void
    {
        if (strlen($body) < 64) {
            throw new RuntimeException("Source « {$label} » : réponse trop courte (".strlen($body).' octets) — '.$url);
        }

        $head = substr($body, 0, 1024);
        if (preg_match('/^\s*(?:<!doctype|<html|<\?xml)/i', $head) || stripos($head, '<html') !== false) {
            throw new RuntimeException(
                "Source « {$label} » : la réponse est une page HTML, pas un CSV (erreur ou redirection ?) — {$url}"
            );
        }
    }
}
