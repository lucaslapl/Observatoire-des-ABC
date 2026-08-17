// Corrections des erreurs présentes dans les registres sources (fautes de
// frappe, communes homonymes, mauvais codes INSEE). Clé : code INSEE erroné.
// Codes vérifiés via geo.api.gouv.fr avant ajout.
export const CORRECTIONS_COMMUNES = {
    // Neuillac (17, Charente-Maritime) → Neulliac (56, Morbihan) — Pontivy Communauté.
    // Code réel : 56146 (le 56124 correspond à Malestroit).
    "17258": {
        code_geographique: "56146",
        libelle_geographique: "Neulliac",
        departement: "56",
        libelle_departement: "Morbihan",
        region: "53",
    },
    // La Celette (18, Cher) → Cellettes (41, Loir-et-Cher) — Agglopolys Blois.
    // Code réel : 41031 (le 41025 correspond à Bracieux).
    "18041": {
        code_geographique: "41031",
        libelle_geographique: "Cellettes",
        departement: "41",
        libelle_departement: "Loir-et-Cher",
        region: "24",
    },
};
export function corrigerCommune(c) {
    const fix = CORRECTIONS_COMMUNES[c.code_geographique];
    if (!fix)
        return c;
    return { ...c, ...fix };
}
