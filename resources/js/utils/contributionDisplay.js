/**
 * Affichage d'une contribution publique dans le panneau admin :
 * décodage du payload JSON et lignes « label / valeur ».
 */

export function parsePayload(p) {
    if (p && typeof p === 'object') return p;
    try {
        return JSON.parse(p || '{}');
    } catch {
        return {};
    }
}

export function payloadLines(payload) {
    const p = payload || {};
    const labels = { termine: 'Terminé', en_cours: 'En cours', va_debuter: 'Va débuter' };
    const lines = [];
    if (p.statut_suggere) lines.push({ label: 'Statut suggéré', value: labels[p.statut_suggere] || p.statut_suggere });
    if (p.annee_debut_suggeree || p.annee_fin_suggeree) {
        const d = p.annee_fin_suggeree ? `${p.annee_debut_suggeree}–${p.annee_fin_suggeree}` : String(p.annee_debut_suggeree);
        lines.push({ label: 'Dates suggérées', value: d });
    }
    if (p.source) lines.push({ label: 'Source', value: p.source });
    if (p.note) lines.push({ label: 'Note', value: p.note });
    if (p.lien) lines.push({ label: 'Lien', value: p.lien, link: true });
    if (p.texte) lines.push({ label: 'Texte', value: p.texte });
    return lines;
}
