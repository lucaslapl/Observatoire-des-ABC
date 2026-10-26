<?php

namespace App\Http\Middleware;

use App\Support\ApiCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache applicatif des réponses GET de l'API v1 : chaque réponse est mise
 * en cache en entier (statut, type, corps) pendant 1 h, clé = route + query
 * string. Un hit est servi sans requête SQL ni sérialisation JSON.
 *
 * Toutes les réponses portent un ETag (SHA-256 du corps, stable tant que
 * les données ne changent pas) et Cache-Control ; un If-None-Match
 * correspondant répond 304 sans corps.
 */
class CacheApiResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $key = ApiCache::key($request);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $response = response($cached['content'], $cached['status'], [
                'Content-Type' => $cached['content_type'],
            ]);

            return $this->finalize($response, $request, $key, store: false);
        }

        return $this->finalize($next($request), $request, $key, store: true);
    }

    /**
     * Poser ETag + Cache-Control, répondre 304 sur If-None-Match, et
     * mettre en cache la réponse si elle vient d'être calculée.
     */
    private function finalize(Response $response, Request $request, string $key, bool $store): Response
    {
        $ttl = (int) config('api.cache_ttl');
        $etag = '"'.hash('sha256', (string) $response->getContent()).'"';

        $response->setEtag($etag);
        $response->headers->set('Cache-Control', 'public, max-age='.$ttl);

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age='.$ttl,
            ]);
        }

        if ($store && $response->isSuccessful()) {
            Cache::put($key, [
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'content' => (string) $response->getContent(),
            ], $ttl);
        }

        return $response;
    }
}
