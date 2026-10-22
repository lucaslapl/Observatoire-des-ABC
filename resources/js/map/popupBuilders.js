/**
 * Construction du HTML des popups Leaflet (fiche projet, contribution,
 * marqueurs multiples). Pures : testables sans DOM.
 */
import {
    COLOR, STATUT_SUGG, STATUT_LABEL, VERDICT, SRC_FR,
    dStatut, srcChip, delBtn,
} from './constants.js';

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Libellé court d'une URL : domaine + début du chemin, jamais l'URL entière. */
export function shortenUrl(raw, max = 48) {
    try {
        const u = new URL(raw);
        let label = u.hostname.replace(/^www\./, '');
        const path = (u.pathname && u.pathname !== '/') ? u.pathname : '';
        if (path) {
            const room = max - label.length;
            label += path.length > room ? path.slice(0, Math.max(room - 1, 2)) + '…' : path;
        }
        return label;
    } catch {
        return raw.length > max ? raw.slice(0, max - 1) + '…' : raw;
    }
}

/** Note de vérification : texte échappé, URL rendues cliquables et raccourcies. */
export function noteHtml(note) {
    const re = /https?:\/\/[^\s<>"']+/g;
    let out = '';
    let last = 0;
    let m;
    while ((m = re.exec(note)) !== null) {
        out += esc(note.slice(last, m.index));
        let url = m[0];
        let trail = '';
        while (/[.,;:!?)]$/.test(url)) {
            trail = url.slice(-1) + trail;
            url = url.slice(0, -1);
        }
        out += `<a href="${esc(url)}" target="_blank" rel="noopener" title="${esc(url)}">${esc(shortenUrl(url))}</a>${esc(trail)}`;
        last = m.index + m[0].length;
    }
    return out + esc(note.slice(last));
}

/** Popup d'un point (une commune d'un projet). */
export function buildPopup(p, isAdmin) {
    const src = p.source || '';
    const srcFr = SRC_FR[src] || src;
    const v = VERDICT[p.verif_etat];
    const confirme = v && ['confirme_termine', 'confirme_en_cours', 'toujours_a_venir', 'confirme_date'].includes(p.verif_etat);
    const verifNote = v
        ? `<br><b style="color:${v.color}">${v.label}</b>` +
        (p.verif_note ? `<br><small>${noteHtml(p.verif_note)}</small>` : '') +
        (p.verif_lien ? `<br><small><a href="${p.verif_lien.replace(/"/g, '&quot;')}" target="_blank" rel="noopener">Lien source</a></small>` : '')
        : '';
    const info = [
        `<b>${p.nom}</b><br>${p.commune}${p.departement ? ' (' + p.departement + ')' : ''}<br>`,
        `Structure : ${p.structure_porteuse || '—'}<br>Année : ${p.annee_debut ? (p.annee_fin ? p.annee_debut + '–' + p.annee_fin : p.annee_debut) : '—'}<br>`,
        `<b>${p.categorie}</b>`,
        !confirme && p.potentiellement_termine ? `<br><b style="color:#8d6e63"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Potentiellement terminé</b><br><small>Début ${p.annee_debut}, durée d'un ABC ≈ 3 ans</small>` : '',
        !confirme && p.potentiellement_en_cours ? `<br><b style="color:#b45309"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Potentiellement en cours</b><br><small>Début annoncé ${p.annee_debut}, toujours « va débuter »</small>` : '',
        !confirme && p.donnees_2022 ? `<br><b style="color:#7f1d1d"><i class="bi bi-clock-history" aria-hidden="true"></i> Statut issu des archives 2022</b><br><small>À vérifier — site officiel hors ligne</small>` : '',
        !confirme && p.estime_termine ? `<br><b style="color:#1b5e20"><i class="bi bi-check-lg" aria-hidden="true"></i> Terminé (estimation)</b><br><small>Statut officiel inconnu, projet débuté en ${p.annee_debut} (> 5 ans)</small>` : '',
        p.anomalie ? `<br><b style="color:#b91c1c"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Commune incohérente</b><br><small>À ${Math.round(p.distance_km)} km de son groupe — non reliée, à vérifier</small>` : '',
        verifNote,
        `<br><small>${p.verifie ? 'Source initiale' : 'Source'} : ${srcFr}</small>`,
        delBtn(p, isAdmin),
    ].join('');
    return info + buildContribForm(p.projet_id);
}

/** Formulaire de contribution intégré au popup. */
export function buildContribForm(pid) {
    return `<div class="pop-contrib" data-sugg data-pid="${pid}">` +
        `<button class="contrib-toggle" data-suggest role="button"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Signaler une correction</button>` +
        `<div class="contrib-form" data-sform hidden>` +
        `<select data-s-type class="form-select form-select-sm">` +
        `<option value="statut">Statut</option><option value="date_debut">Date de début</option><option value="note">Note</option><option value="lien">Lien</option><option value="autre">Autre info</option>` +
        `</select>` +
        `<select data-s-statut class="form-select form-select-sm" hidden>` +
        `<option value="">Choisir un statut…</option>` +
        STATUT_SUGG.map(([k, l]) => `<option value="${k}">${l}</option>`).join('') +
        `</select>` +
        `<div data-s-date hidden class="d-flex gap-1 flex-wrap">` +
        `<input type="number" data-s-annee min="1990" max="2040" placeholder="Début (ex. 2023)" class="form-control form-control-sm" style="width:110px" />` +
        `<input type="number" data-s-annee-fin min="1990" max="2040" placeholder="Fin (optionnel)" class="form-control form-control-sm" style="width:110px" />` +
        `<input type="text" data-s-source placeholder="Source (organisme, article…)" class="form-control form-control-sm" style="min-width:160px" />` +
        `</div>` +
        `<input type="text" data-s-note placeholder="Note" class="form-control form-control-sm" hidden />` +
        `<input type="text" data-s-lien placeholder="Lien (URL)" class="form-control form-control-sm" hidden />` +
        `<input type="text" data-s-texte placeholder="Votre information" class="form-control form-control-sm" hidden />` +
        `<input type="text" data-s-commentaire placeholder="Commentaire (optionnel)" class="form-control form-control-sm" />` +
        `<button type="button" class="btn btn-sm btn-success" data-send>Envoyer la suggestion</button>` +
        `<span data-s-result class="saved"></span>` +
        `</div></div>`;
}

/** Ligne « un projet » du popup multi-marqueur. */
export function buildMultiRow(p, isAdmin) {
    const s = dStatut(p);
    const color = COLOR[s] || COLOR.inconnu;
    const sLabel = STATUT_LABEL[s] || s;
    const year = p.annee_debut ? ` · ${p.annee_debut}${p.annee_fin ? '–' + p.annee_fin : ''}` : '';
    const v = VERDICT[p.verif_etat];
    const verdict = v ? `<span class="multi-badge multi-verdict" style="border-color:${v.color};color:${v.color}">${v.label}</span>` : '';
    const figee = (p.donnees_2022 || p.source === 'wayback')
        ? `<div class="multi-srcnote">Statut figé à l'instantané 2022 → à vérifier (site hors ligne).</div>`
        : '';
    return `<div class="multi-row">` +
        `<span class="multi-chip" style="background:${color}"></span>` +
        `<div class="multi-main"><div class="multi-name">${p.nom}</div>` +
        `<div class="multi-tags"><span class="multi-badge" style="border-color:${color};color:${color}">${sLabel}${year}</span>` +
        srcChip(p) + verdict + `</div>${figee}</div>` +
        delBtn(p, isAdmin) +
        `</div>`;
}

/** En-tête du popup multi-marqueur. */
export function buildMultiHeader(count, nbCommunes, isAdmin) {
    return `<div class="multi-head"><span class="multi-count">${count}</span> ABC sur ce territoire` +
        (nbCommunes ? ` · ${nbCommunes} commune${nbCommunes > 1 ? 's' : ''}` : '') +
        (isAdmin ? '<div class="multi-adminnote">Admin : <i class="bi bi-trash" aria-hidden="true"></i> supprime un projet jugé erroné.</div>' : '') +
        `</div>`;
}
