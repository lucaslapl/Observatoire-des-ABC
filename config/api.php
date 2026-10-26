<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API publique v1 (lecture seule)
    |--------------------------------------------------------------------------
    |
    | Toutes les données exposées (sources agrégées + contributions humaines)
    | sont réutilisables sous Licence Ouverte 2.0 (Etalab). L'accès est
    | ouvert, sans clé, avec un throttling par IP ; le cache applicatif
    | sert la majorité des requêtes sans toucher la base.
    |
    */

    'licence' => 'Etalab-2.0',
    'licence_url' => 'https://www.etalab.gouv.fr/wp-content/uploads/2017/04/ETALAB-Licence-Ouverte-v2.0.pdf',

    // Mention de crédit attendue des réutilisateurs (%s = date de mise à jour).
    'credit' => "Source : Observatoire des ABC (observatoire-abc.fr), enrichi par ses contributeurs, d'après les données de l'OFB et du Fonds vert — sous Licence Ouverte Etalab 2.0. Mise à jour : %s.",

    /*
    | Cache applicatif des réponses GET : durée de vie et invalidation.
    | L'invalidation passe par un « epoch » (ApiCache) : toute mise à jour
    | des données ou contribution validée incrémente l'epoch, ce qui rend
    | toutes les clés existantes inaccessibles (elles expirent seules
    | après le TTL).
    */
    'cache_ttl' => 3600,

    /*
    | Pagination par curseur : taille de page par défaut et plafond.
    */
    'per_page' => 25,
    'max_per_page' => 100,

    /*
    | Throttling : requêtes par minute et par IP, toutes routes v1.
    */
    'throttle_per_minute' => 60,

    /*
    | Libellés de provenance exposés par enregistrement, par identifiant
    | de source (colonne `projets.source`).
    */
    'sources_origine' => [
        'data.gouv' => 'Registre national des ABC — OFB / data.gouv.fr',
        'wayback' => 'Registre national des ABC (archives 2022) — OFB / Wayback Machine',
        'fondsvert-p113-2024' => 'Fonds vert 2024',
        'fondsvert-p113-2025' => 'Fonds vert 2025',
    ],
];
