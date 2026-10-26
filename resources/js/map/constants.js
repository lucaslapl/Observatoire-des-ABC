/**
 * Constantes et helpers partagés de la carte et de l'admin
 * (couleurs, libellés, badges, filtrage des features GeoJSON).
 */

export const COLOR = { en_cours: '#2e7d32', a_venir: '#ef6c00', termine: '#1565c0', inconnu: '#9e9e9e' };

/**
 * Palettes de statut pour la carte — exploration visuelle
 * (branche test/design-marqueurs-carte). « defaut » = palette historique.
 * Les autres suivent la métaphore de pousse : bourgeon clair (va débuter) →
 * vert développé (en cours) → teinte posée (terminé).
 * S'appliquent via applyPalette(), activables en local avec ?palettes=1.
 */
export const PALETTES = {
    defaut: { label: 'Défaut (actuelle)', statuts: { en_cours: '#2e7d32', a_venir: '#ef6c00', termine: '#1565c0', inconnu: '#9e9e9e' } },
    pousse: { label: 'Pousse (bourgeon → développé)', statuts: { en_cours: '#3a7d44', a_venir: '#7cb342', termine: '#4f6d9e', inconnu: '#a09a92' } },
    herbier: { label: 'Herbier (profond, séché)', statuts: { en_cours: '#2d6a4f', a_venir: '#9ccc65', termine: '#8d6e63', inconnu: '#a8a29e' } },
    canopee: { label: 'Canopée (frais, lumière)', statuts: { en_cours: '#3a7d44', a_venir: '#b7d768', termine: '#227c9d', inconnu: '#b8b2a6' } },
};

const PALETTE_CSS_VAR = { en_cours: '--ok', a_venir: '--debut', termine: '--fini', inconnu: '--inconnu' };

/**
 * Applique une palette : mute COLOR en place (tous les importeurs — carte,
 * popups, légende — voient le changement) et synchronise les variables CSS
 * de la légende. Clé inconnue = palette par défaut.
 */
export function applyPalette(key) {
    const p = PALETTES[key] || PALETTES.defaut;
    Object.assign(COLOR, p.statuts);
    if (typeof document !== 'undefined') {
        for (const [statut, variable] of Object.entries(PALETTE_CSS_VAR)) {
            document.documentElement.style.setProperty(variable, p.statuts[statut]);
        }
    }
    return PALETTES[key] ? key : 'defaut';
}

export const METRO = [[41.0, -5.6], [51.5, 10.0]];

export const STATUT_LABEL = { en_cours: 'En cours', a_venir: 'Va débuter', termine: 'Terminé', inconnu: 'Statut inconnu' };

export const VERDICT = {
    confirme_termine: { label: '✓ Vérifié : Terminé', color: '#1b5e20' },
    confirme_en_cours: { label: '✓ Vérifié : En cours', color: '#2e7d32' },
    toujours_a_venir: { label: '✓ Vérifié : Va débuter', color: '#b45309' },
    confirme_date: { label: '✓ Vérifié : Date confirmée', color: '#0d9488' },
    introuvable: { label: '⚠ Vérifié : introuvable', color: '#7f1d1d' },
    douteux: { label: '⚠ Vérifié : incertain', color: '#7f1d1d' },
};

export const SRC_LABEL = {
    'data.gouv': 'Registre OFB',
    wayback: 'Archives 2022',
    'fondsvert-p113-2024': 'Fonds vert 2024',
    'fondsvert-p113-2025': 'Fonds vert 2025',
};

export const SRC_FR = {
    'data.gouv': 'Registre OFB',
    wayback: 'Registre OFB (archives 2022)',
    'fondsvert-p113-2024': 'Fonds vert 2024',
    'fondsvert-p113-2025': 'Fonds vert 2025',
};

export const srcLabel = (s) => SRC_LABEL[s] || s || '—';

/** Statut affiché : la vérification manuelle prime sur le statut source. */
export const dStatut = (p) => p.statut_affichage || p.statut;

/** Badge de source : « Archives 2022 » est un instantané figé (dépréciable). */
export function srcChip(p) {
    const l = srcLabel(p.source);
    if (p.donnees_2022 || p.source === 'wayback') {
        return `<span class="multi-badge multi-src src-stale" title="Projet figé à l'instantané de 2022 — statut possiblement obsolète">${l}</span>`;
    }
    return `<span class="multi-badge multi-src">${l}</span>`;
}

/** Bouton de suppression admin pour un projet. */
export function delBtn(p, isAdmin) {
    if (!isAdmin) return '';
    const nom = (p.nom || '').replace(/"/g, '&quot;');
    return `<button type="button" class="btn-del" data-del-pid="${p.projet_id}" data-del-nom="${nom}" title="Supprimer ce projet (admin)"><i class="bi bi-trash" aria-hidden="true"></i></button>`;
}

export function fmtCount(n, s) {
    return n + ' ' + (n > 1 ? s : s.replace(/s$/, ''));
}

/** Une feature GeoJSON passe-t-elle les filtres actifs ? */
export function visible(f, s) {
    const p = f.properties;
    if (s.statut && dStatut(p) !== s.statut) return false;
    if (s.region && p.region !== s.region) return false;
    if (s.yearFrom || s.yearTo) {
        if (p.annee_debut == null) return false;
        const y = Number(p.annee_debut);
        if (s.yearFrom && y < Number(s.yearFrom)) return false;
        if (s.yearTo && y > Number(s.yearTo)) return false;
    }
    if (s.q) {
        const hay = `${p.nom || ''} ${p.commune || ''} ${p.structure_porteuse || ''} ${p.departement || ''}`.toLowerCase();
        if (!hay.includes(s.q)) return false;
    }
    return true;
}
