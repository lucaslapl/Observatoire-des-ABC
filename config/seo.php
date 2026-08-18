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

    'default_keywords' => 'Atlas de la Biodiversité Communale, ABC, observatoire, biodiversité, communes, OFB, Fonds vert',

    'canonical_base' => null, // null = racine du domaine (url('/'))

    'og_image' => '/og-image.png',

    'og_type' => 'website',

    'twitter_handle' => null,

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
    | Mesure d'audience (consentement RGPD).
    |--------------------------------------------------------------------------
    | Rien n'est chargé tant que le visiteur n'a pas accepté : la bannière de
    | consentement (Layout) active le déclencheur choisi. Options : Matomo
    | (autohébergé recommandé, MATOMO_URL + MATOMO_SITE_ID) ou Google gtag
    | (GTAG_ID). Laissez vides pour désactiver.
    */

    'tracking' => [
        'matomo_url' => env('MATOMO_URL'),
        'matomo_site_id' => env('MATOMO_SITE_ID'),
        'gtag_id' => env('GTAG_ID'),
    ],

];
