<?php

return [

    /**
     * Sources de données (fichiers CSV téléchargés, cache local).
     * Clé = identifiant utilisé dans les colonnes `source`/`snapshots.source`.
     */
    'sources' => [
        'datagouv' => 'https://static.data.gouv.fr/resources/atlas-de-la-biodiversite-communale-abc/20260709-081207/atlas-biodiversite.csv',
        'wayback' => 'https://web.archive.org/web/20221206082701/https://abc.naturefrance.fr/abcexport?page&_format=csv',
        'fondsvert2024' => 'https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20250731-100849/fonds-vert-p113-2024-export.csv',
        'fondsvert2025' => 'https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20260728-085038/fonds-vert-p113-2025-export.csv.csv',
    ],

    /**
     * Fraîcheur des sources (date de publication des fichiers utilisés).
     */
    'source_dates' => [
        'data.gouv' => '2026-07-09',
        'wayback' => '2022-12-06',
        'fondsvert-p113-2024' => '2025-07-31',
        'fondsvert-p113-2025' => '2026-06-22',
    ],

    /**
     * User-Agent envoyé aux API exposées publiquement.
     */
    'user_agent' => 'observatoire-des-abc/0.2 (recherche ouverte sur les Atlas de la Biodiversité Communale)',

    /**
     * Délai (ms) entre deux requêtes réseau lors du téléchargement.
     */
    'request_delay_ms' => 500,

    /**
     * Répertoire de cache des fichiers CSV téléchargés (sous storage/app).
     */
    'cache_dir' => storage_path('app/abc/cache'),

    /**
     * Chemin vers la base SQLite héritée (pour abc:import-legacy).
     */
    'legacy_db' => base_path('data/abc.db'),

    /**
     * Répertoire d'export (CSV / GeoJSON).
     */
    'export_dir' => storage_path('app/abc/exports'),

    /**
     * Nombre de sauvegardes quotidiennes conservées (rotation).
     */
    'backup_retention' => 14,

    /**
     * Collect mensuel automatique (désactivable en prod : COLLECT_AUTOMATIC=false).
     */
    'collect_automatic' => (bool) env('COLLECT_AUTOMATIC', false),

    /**
     * Seuil de distance (km) au-delà duquel une commune est une anomalie.
     */
    'anomalie_km' => 100,

    /**
     * Durée (ans) d'un ABC pour les flags potentiellement_*.
     */
    'duree_abc_ans' => 3,

    /**
     * Durée (ans) pour "estimé terminé" (statut inconnu + début ancien).
     */
    'duree_estime_termine_ans' => 5,

    /**
     * Année minimale de début prise en compte pour les calculs de statut.
     */
    'annee_min' => 2000,

    /**
     * Compte administrateur seedé depuis l'environnement.
     */
    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
