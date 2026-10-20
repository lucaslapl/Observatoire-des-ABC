<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identité du site pour le référencement.
    |--------------------------------------------------------------------------
    */

    'site_name' => 'Observatoire des ABC',

    'locale' => 'fr_FR',

    'default_title' => 'Observatoire des ABC — Atlas de la Biodiversité Communale',

    'default_description' => 'Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des projets financés (Registre OFB, Fonds vert), carte interactive, statuts vérifiés et contributions du public.',

    'canonical_base' => env('SEO_CANONICAL_BASE'), // null = racine du domaine (url('/'))

    'og_image' => '/og-image.png',

    'og_image_width' => 1200,

    'og_image_height' => 630,

    'og_image_alt' => 'Observatoire des ABC — carte des Atlas de la Biodiversité Communale',

    'og_type' => 'website',

    'twitter_handle' => env('SEO_TWITTER_HANDLE'),

    /*
    |--------------------------------------------------------------------------
    | Sources des données (ce sont elles qui détiennent la licence originelle).
    |--------------------------------------------------------------------------
    | L'observatoire réutilise les données telles que publiées par leurs
    | producteurs : Registre OFB (data.gouv.fr) et Fonds vert (data.gouv.fr).
    */

    'sources' => [
        'data.gouv' => 'https://data.gouv.fr',
        'registre_ofb' => 'https://data.gouv.fr/reuses/les-atlas-de-la-biodiversite-communale',
    ],

    'license_note' => 'Données issues des registres publics (data.gouv.fr) — Atlas de la Biodiversité Communale et Fonds vert. Reuse des données telles que publiées par leurs producteurs.',

    /*
    |--------------------------------------------------------------------------
    | Mesure d'audience (RGPD).
    |--------------------------------------------------------------------------
    | Matomo (autohébergé, MATOMO_URL + MATOMO_SITE_ID) : mesure d'audience
    | anonyme exemptée de consentement — le traceur se charge par défaut et
    | le refus (bannière / lien « Cookies » du Layout) est mémorisé.
    | Laissez vides pour désactiver.
    */

    'tracking' => [
        'matomo_url' => env('MATOMO_URL'),
        'matomo_site_id' => env('MATOMO_SITE_ID'),
    ],

];
