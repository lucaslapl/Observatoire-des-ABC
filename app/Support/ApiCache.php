<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cache applicatif des réponses de l'API publique v1.
 *
 * Chaque réponse GET est mise en cache en entier, sous une clé dérivée de
 * la méthode + route + query string normalisée, préfixée par un « epoch ».
 * Toute mise à jour des données (collect, statuts, vérifications,
 * contributions validées, journal) incrémente l'epoch : les clés de l'epoch
 * précédent deviennent inaccessibles et expirent seules après le TTL (1 h).
 * Cela évite de dépendre des tags de cache, indisponibles sur file/database.
 */
class ApiCache
{
    private const EPOCH_KEY = 'abc:apiv1:epoch';

    public static function epoch(): int
    {
        return (int) Cache::get(self::EPOCH_KEY, 0);
    }

    /**
     * Clé de cache d'une requête GET : epoch + méthode + chemin + query
     * string triée (l'ordre des paramètres ne doit pas multiplier les
     * clés). La méthode fait partie de la clé : un HEAD ne doit jamais
     * partager l'entrée d'un GET.
     */
    public static function key(Request $request): string
    {
        $params = $request->query();
        ksort($params);

        return 'abc:apiv1:'.self::epoch().':'
            .md5($request->getMethod().' '.$request->getPathInfo().'?'.http_build_query($params));
    }

    /**
     * Invalide tout le cache de réponses : collect, recalcul de statuts,
     * géocodage, vérification, contribution validée, journal d'audit…
     */
    public static function invalidate(): void
    {
        Cache::forever(self::EPOCH_KEY, self::epoch() + 1);
    }
}
