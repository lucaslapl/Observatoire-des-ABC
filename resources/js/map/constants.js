/**
 * Constantes et helpers partagés de la carte et de l'admin
 * (couleurs, libellés, badges, filtrage des features GeoJSON).
 */

export const COLOR = { en_cours: '#2e7d32', a_venir: '#ef6c00', termine: '#1565c0', inconnu: '#9e9e9e' };

/**
 * Palettes de statut pour la carte — exploration visuelle
 * (branche test/design-marqueurs-carte). « defaut » = palette historique.
 * S'appliquent via applyPalette(), activables en local avec ?palettes=1.
 */
export const PALETTES = {
    defaut: { label: 'Défaut (actuelle)', statuts: { en_cours: '#2e7d32', a_venir: '#ef6c00', termine: '#1565c0', inconnu: '#9e9e9e' } },
    herbier: { label: 'Herbier', statuts: { en_cours: '#5a7d2a', a_venir: '#c98a2d', termine: '#2f6690', inconnu: '#9a8f7f' } },
    carte: { label: 'Carte ancienne', statuts: { en_cours: '#6b8e23', a_venir: '#b8860b', termine: '#4a6fa5', inconnu: '#8d8378' } },
    sobre: { label: 'Contemporain sobre', statuts: { en_cours: '#3a7d44', a_venir: '#d98324', termine: '#4f6d9e', inconnu: '#a09a92' } },
    sobre_affine: { label: 'Sobre affiné (ambre du site)', statuts: { en_cours: '#3a7d44', a_venir: '#b45309', termine: '#4f6d9e', inconnu: '#a09a92' } },
    contrastee: { label: 'Contrastée (teintes neuves)', statuts: { en_cours: '#0e7490', a_venir: '#db2777', termine: '#6d28d9', inconnu: '#78716c' } },
    monochrome: { label: 'Monochrome forêt', statuts: { en_cours: '#1b4332', a_venir: '#74c69d', termine: '#40916c', inconnu: '#adb5bd' } },
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
