<?php

/**
 * Corrections des erreurs présentes dans les registres sources (fautes de
 * frappe, communes homonymes, mauvais codes INSEE). Codes vérifiés via
 * geo.api.gouv.fr avant ajout.
 *
 * Deux niveaux :
 * - « par_code » : correction globale, clé = code INSEE erroné. À n'utiliser
 *   que si le code erroné ne peut jamais être légitime dans aucun projet.
 * - « par_projet » : correction ciblée, clé = id projet puis code INSEE
 *   erroné. Obligatoire quand le code est valide par ailleurs (commune
 *   homonyme existant réellement : Saint-Pierre 97416 à La Réunion /
 *   97222 en Martinique) : la correction ne doit pas casser un futur projet
 *   qui utiliserait légitimement le code d'origine.
 */
return [
    'par_code' => [
        // Neuillac (17, Charente-Maritime) → Neulliac (56, Morbihan) — Pontivy Communauté.
        // Code réel : 56146 (le 56124 correspond à Malestroit).
        '17258' => [
            'code_geographique' => '56146',
            'libelle_geographique' => 'Neulliac',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => '53',
        ],
        // La Celette (18, Cher) → Cellettes (41, Loir-et-Cher) — Agglopolys Blois.
        // Code réel : 41031 (le 41025 correspond à Bracieux).
        '18041' => [
            'code_geographique' => '41031',
            'libelle_geographique' => 'Cellettes',
            'departement' => '41',
            'libelle_departement' => 'Loir-et-Cher',
            'region' => '24',
        ],
        // Chelles (60, Oise) → Chelles (77, Seine-et-Marne) — Communauté
        // d'agglomération Paris - Vallée de la Marne. L'instantané Wayback 2022
        // utilise par erreur le code INSEE 60145 (Chelles dans l'Oise) au lieu de
        // 77108 (Chelles en Seine-et-Marne). Le registre data.gouv est correct.
        '60145' => [
            'code_geographique' => '77108',
            'libelle_geographique' => 'Chelles',
            'departement' => '77',
            'libelle_departement' => 'Seine-et-Marne',
            'region' => '11',
        ],
    ],

    'par_projet' => [
        // ABC du Nord de la Martinique : le registre OFB (et l'instantané
        // Wayback 2022) rattache Saint-Pierre avec le code INSEE 97416 —
        // Saint-Pierre, La Réunion (CIVIS) — au lieu de 97222, Saint-Pierre
        // en Martinique (commune réelle du Nord de l'île). Le code 97416
        // étant valide pour La Réunion, la correction est ciblée sur ce
        // projet pour ne pas casser un futur ABC réunionnais.
        // Deux ids possibles selon la source qui (re)crée le projet.
        'abc-du-nord-de-la-martinique-martinique' => [
            '97416' => [
                'code_geographique' => '97222',
                'libelle_geographique' => 'Saint-Pierre',
                'epci' => '200041788',
                'libelle_epci' => "Communauté d'agglomération du Pays Nord Martinique",
                'departement' => '972',
                'libelle_departement' => 'Martinique',
                'region' => '2',
                'libelle_pnr' => 'PNR de Martinique',
            ],
        ],
        // Même projet vu de l'instantané Wayback 2022 (structure porteuse
        // différente → id différent). Ne sert que si le projet disparaît du
        // registre data.gouv et est recréé depuis les archives.
        'abc-du-nord-de-la-martinique-parc-naturel-regional' => [
            '97416' => [
                'code_geographique' => '97222',
                'libelle_geographique' => 'Saint-Pierre',
                'departement' => '972',
                'libelle_departement' => 'Martinique',
                'region' => '2',
            ],
        ],
    ],
];
