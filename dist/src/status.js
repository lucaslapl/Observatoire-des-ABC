const RAW_TO_AGREGE = {
    "En cours de réalisation": "en_cours",
    Fini: "termine",
    "En phase de lancement": "a_venir",
    "Non commencé": "a_venir",
    Inconnu: "inconnu",
};
export function avancementToStatut(raw) {
    if (!raw)
        return "inconnu";
    return RAW_TO_AGREGE[raw] ?? "inconnu";
}
const CATEGORIE_LABEL = {
    va_debuter: "Va débuter",
    a_venir: "Va débuter",
    en_cours: "En cours",
    termine: "Terminé",
    inconnu: "Statut inconnu",
};
export function statutLabel(s) {
    return CATEGORIE_LABEL[s];
}
// Combine le statut actuel avec l'historique des snapshots Wayback :
// si jamais documenté "Fini" sur un snapshot antérieur, on considère le projet terminé.
export function resolveCategorie(statutCourant, snapshotAvancements, viaFondsVert2025) {
    if (snapshotAvancements.includes("Fini"))
        return "termine";
    if (statutCourant === "a_venir" && viaFondsVert2025)
        return "va_debuter";
    if (statutCourant === "a_venir")
        return "va_debuter";
    if (statutCourant === "en_cours")
        return "en_cours";
    if (statutCourant === "termine")
        return "termine";
    return "inconnu";
}
export const CATEGORIE_ORDER = {
    en_cours: 0,
    va_debuter: 1,
    termine: 2,
    inconnu: 3,
};
// Un ABC dure ~3 ans. Si le statut est encore "en cours" et que le projet a
// commencé il y a plus de DUREE_ABC_ANS, il est probablement terminé mais
// non mis à jour dans les sources.
export const DUREE_ABC_ANS = 3;
export function estPotentiellementTermine(statut, anneeDebut, anneeCourante = new Date().getFullYear()) {
    if (statut !== "en_cours")
        return false;
    if (!anneeDebut)
        return false;
    return anneeCourante - anneeDebut > DUREE_ABC_ANS;
}
// Verdict de vérification manuelle → statut affiché sur la carte.
// Les verdicts non concluants (introuvable, douteux, à vérifier) ne changent rien.
export function statutDepuisVerification(etat) {
    if (etat === "confirme_termine")
        return "termine";
    if (etat === "confirme_en_cours")
        return "en_cours";
    if (etat === "toujours_a_venir")
        return "a_venir";
    return null;
}
