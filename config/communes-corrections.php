<?php

/**
 * Corrections des erreurs présentes dans les registres sources (fautes de
 * frappe, communes homonymes, mauvais codes INSEE). Clé : code INSEE erroné.
 * Codes vérifiés via geo.api.gouv.fr avant ajout.
 */
return [
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
];
