<?php

return [

    /**
     * Sources de données (fichiers CSV téléchargés, cache local).
     * Clé = identifiant utilisé dans les colonnes `source`/`snapshots.source`.
     *
     * Les URLs ci-dessous sont des révisions PINNÉES (valeur de repli) : les
     * clés listées dans `source_resolution` sont re-résolues à chaque collect
     * via l'API data.gouv pour suivre la dernière révision publiée.
     */
    'sources' => [
        'datagouv' => 'https://static.data.gouv.fr/resources/atlas-de-la-biodiversite-communale-abc/20260709-081207/atlas-biodiversite.csv',
        'wayback' => 'https://web.archive.org/web/20221206082701/https://abc.naturefrance.fr/abcexport?page&_format=csv',
        'fondsvert2024' => 'https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20250731-100849/fonds-vert-p113-2024-export.csv',
        'fondsvert2025' => 'https://static.data.gouv.fr/resources/fonds-vert-liste-des-projets-subventionnes/20260728-085038/fonds-vert-p113-2025-export.csv.csv',
    ],

    /**
     * Résolution automatique des URLs sources via l'API data.gouv
     * (DataGouvResolver). `match` = fragment de l'URL de la ressource.
     * wayback = archive figée, jamais re-résolue.
     */
    'auto_resolve_sources' => (bool) env('SOURCES_AUTO_RESOLVE', true),
    'source_resolution' => [
        'datagouv' => [
            'dataset' => 'atlas-de-la-biodiversite-communale-abc',
            'match' => 'atlas-biodiversite.csv',
        ],
        'fondsvert2024' => [
            'dataset' => 'fonds-vert-liste-des-projets-subventionnes',
            'match' => 'fonds-vert-p113-2024-export.csv',
        ],
        'fondsvert2025' => [
            'dataset' => 'fonds-vert-liste-des-projets-subventionnes',
            'match' => 'fonds-vert-p113-2025-export.csv',
        ],
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
     * Commande exécutée après chaque sauvegarde réussie (copie distante
     * obligatoire : un dump sur le même disque que la base n'est pas une
     * sauvegarde). `{file}` est remplacé par le chemin du dump.
     * Exemples : "rclone copy {file} remote:observatoire-abc"
     *            "scp {file} backup@nas:/srv/backups/"
     * Vide = aucun envoi.
     */
    'backup_upload_cmd' => env('BACKUP_UPLOAD_CMD'),

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
     * Adresse de réception des messages du formulaire de contact
     * (page /contact, signalée par la bannière de développement).
     * Indépendante de MAIL_FROM_ADDRESS (expéditeur des emails du site).
     */
    'contact_email' => env('CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'contact@observatoire-abc.fr')),

    /**
     * Compte administrateur seedé depuis l'environnement.
     */
    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
