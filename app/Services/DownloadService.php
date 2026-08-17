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
            echo "[cache] {$label} → {$dest}\n";

            return $dest;
        }

        echo "[download] {$label} …\n";
        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => config('abc.user_agent'),
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} pour {$url}");
        }

        $tmp = $dest.'.part';
        file_put_contents($tmp, $response->body());
        rename($tmp, $dest);
        usleep(config('abc.request_delay_ms') * 1000);

        return $dest;
    }
}
