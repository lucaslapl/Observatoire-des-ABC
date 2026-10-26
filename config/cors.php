<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS — API publique v1 uniquement
    |--------------------------------------------------------------------------
    |
    | Ouvert aux sites tiers : la réutilisation (cartes, synthèses…) doit
    | pouvoir interroger l'API depuis un navigateur. L'API historique
    | (non versionnée, utilisée par le site lui-même) reste hors CORS.
    |
    | ETag est exposé : il faut le lister explicitement pour que les
    | navigateurs tiers puissent le lire et renvoyer If-None-Match.
    */

    'paths' => ['api/v1/*'],

    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['ETag', 'Cache-Control'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
