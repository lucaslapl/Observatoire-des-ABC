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
     * Référentiels INPN (ZNIEFF, Natura 2000, aires protégées).
     *
     * `dir` n'est JAMAIS dans le dépôt (voir .gitignore) : les données
     * brutes viennent des téléchargements INPN et ne sont importées que
     * localement (abc:import-inpn), puis exportées en SQL portable vers
     * la production (abc:export-inpn) — l'hébergeur n'a pas PostGIS.
     *
     * `admin_express_dir` doit contenir le GeoJSON des communes
     * (Admin Express COG, IGN, Licence Ouverte 2.0) : il sert uniquement
     * à la jointure spatiale aires protégées ↔ communes, calculée en
     * local au moment de l'import.
     *
     * `versions` : date de référence de chaque base, affichée avec
     * l'attribution INPN (page Méthodologie).
     */
    'inpn' => [
        'dir' => base_path('data/inpn-data'),
        'admin_express_dir' => base_path('data/inpn-data/admin-express'),
        'versions' => [
            'znieff' => 'extraction ZNIEFF INPN 2025 (ZIP de secours)',
            'natura' => 'Base Natura 2000 — décembre 2024',
            'aires' => 'Inventaire des aires protégées APA-INPN — extraction juillet 2025',
        ],
    ],

    /**
     * Répertoire d'export (CSV / GeoJSON).
     */
    'export_dir' => storage_path('app/abc/exports'),

    /**
     * Nombre de sauvegardes conservées (rotation ; 2/jour = 7 jours).
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
     * Binaire pg_dump utilisé par abc:backup. Chemin complet si le cron
     * de l'hébergeur n'a pas /usr/bin dans son PATH (ex. Plesk) :
     * BACKUP_PG_DUMP_BIN=/usr/bin/pg_dump
     */
    'backup_pg_dump_bin' => env('BACKUP_PG_DUMP_BIN', 'pg_dump'),

    /**
     * Collect mensuel automatique (désactivable en prod : COLLECT_AUTOMATIC=false).
     */
    'collect_automatic' => (bool) env('COLLECT_AUTOMATIC', false),

    /**
     * Seuil de distance (km) au-delà duquel une commune est une anomalie.
     */
    'anomalie_km' => 100,

    /**
     * Rayon (km) de voisinage sur la fiche projet : projets ABC dont au
     * moins une commune géocodée se trouve à moins de cette distance
     * d'une commune du périmètre.
     */
    'voisinage_km' => 15,

    /**
     * Communes épargnées par la détection d'anomalie géographique, bien
     * qu'au-delà du seuil : grands territoires réels dont une partie des
     * communes est naturelment éloignée du centroïde. Clé = id de projet,
     * valeur = codes géographiques (INSEE) des communes.
     *
     * Epidor (EPTB Dordogne) couvre tout le bassin de la Dordogne, de la
     * source (Auvergne) à l'estuaire (Gironde) : Coutras (33) est légitime.
     */
    'anomalie_exceptions' => [
        'abc-de-la-vallee-de-la-dordogne-epidor-epidor-eptb-dordogne-2018' => ['33138'],
    ],

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
