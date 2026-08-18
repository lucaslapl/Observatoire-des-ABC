<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import axios from 'axios';

let L = null;
let html2canvas = null;
let jsPDF = null;

const props = defineProps({
    meta: {
        type: Object,
        default: null,
    },
    index: {
        type: Object,
        default: () => ({ regions: [], departements: [] }),
    },
    isAdmin: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const site = computed(() => page.props.site || {});
const fmtSourceDate = (d) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const seoJsonLd = computed(() => {
    const meta = props.meta || {};
    const sources = meta.sources || {};
    const dates = Object.values(sources).filter(Boolean).sort();
    const datePublished = dates.length ? dates[dates.length - 1] : undefined;
    const count = meta.countProjets || 0;

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            '@id': '#organization',
            name: 'Observatoire des ABC',
            url: undefined,
            description:
                'Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des projets financés, carte interactive et vérifications.',
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Dataset',
            name: 'Observatoire des Atlas de la Biodiversité Communale (ABC)',
            description: `${count} projets ABC suivis en France Métropolitaine et Outre-mer : statut, communes, porteur, périodes et sources (Registre OFB, Fonds vert, archives 2022).`,
            url: undefined,
            license: 'https://www.etalab.gouv.fr/licence-ouverte-open-licence/',
            publisher: { '@id': '#organization' },
            ...(datePublished ? { dateModified: datePublished } : {}),
            ...(count ? { variableMeasured: [{ name: 'nombre de projets ABC suivis', value: count, unitText: 'projets' }] } : {}),
        },
    ];
});

const navRef = ref(null);
const mapRef = ref(null);
const legendRef = ref(null);
const sidebarRef = ref(null);

const filters = reactive({ statut: '', region: '', yearFrom: '', yearTo: '', q: '' });
const regionOptions = ref([]);
const yearOptions = ref([]);
const counts = reactive({ en_cours: 0, a_venir: 0, termine: 0, inconnu: 0, projets: 0, verif: 0, estime: 0, pt: 0, pec: 0, stale: 0, ano: 0 });
const countLine = ref('chargement…');
const suggBadge = ref(0);
const legendRetracted = ref(false);
const tileOpen = ref(false);
const exportOpen = ref(false);
const tileIndex = ref(0);
const exporting = ref(false);

const tiles = [
    { name: 'Plan', url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', attr: '© OpenStreetMap' },
    { name: 'Clair', url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', attr: '© OpenStreetMap © CARTO' },
    { name: 'Sombre', url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', attr: '© OpenStreetMap © CARTO' },
    { name: 'Satellite', url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', attr: '© Esri' },
];

const COLOR = { en_cours: '#2e7d32', a_venir: '#ef6c00', termine: '#1565c0', inconnu: '#9e9e9e' };
const METRO = [[41.0, -5.6], [51.5, 10.0]];
const STATUT_SUGG = [['termine', 'Terminé'], ['en_cours', 'En cours'], ['va_debuter', 'Va débuter']];
const VERDICT = {
    confirme_termine: { label: '✓ Vérifié : Terminé', color: '#1b5e20' },
    confirme_en_cours: { label: '✓ Vérifié : En cours', color: '#2e7d32' },
    toujours_a_venir: { label: '✓ Vérifié : Va débuter', color: '#b45309' },
    confirme_date: { label: '✓ Vérifié : Date confirmée', color: '#0d9488' },
    introuvable: { label: '⚠ Vérifié : introuvable', color: '#7f1d1d' },
    douteux: { label: '⚠ Vérifié : incertain', color: '#7f1d1d' },
};
const SRC_LABEL = {
    'data.gouv': 'Registre OFB',
    wayback: 'Archives 2022',
    'fondsvert-p113-2024': 'Fonds vert 2024',
    'fondsvert-p113-2025': 'Fonds vert 2025',
};
const srcLabel = (s) => SRC_LABEL[s] || s || '—';

// Badge de source : « Archives 2022 » est un instantané figé (dépréciable).
function srcChip(p) {
    const l = srcLabel(p.source);
    if (p.donnees_2022 || p.source === 'wayback') {
        return `<span class="multi-badge multi-src src-stale" title="Projet figé à l'instantané de 2022 — statut possiblement obsolète">${l}</span>`;
    }
    return `<span class="multi-badge multi-src">${l}</span>`;
}

// Bouton de suppression admin pour un projet.
function delBtn(p) {
    if (!props.isAdmin) return '';
    const nom = (p.nom || '').replace(/"/g, '&quot;');
    return `<button type="button" class="btn-del" data-del-pid="${p.projet_id}" data-del-nom="${nom}" title="Supprimer ce projet (admin)">🗑</button>`;
}

let map = null;
let tileLayer = null;
let layer = null;
let all = [];
let allProjs = new Set();

const dStatut = (p) => p.statut_affichage || p.statut;

function visible(f, s) {
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

function inViewport(f) {
    if (!map) return false;
    const [lon, lat] = f.geometry.coordinates;
    return map.getBounds().contains([lat, lon]);
}

function visibleIn(f, s) {
    return visible(f, s) && inViewport(f);
}

function buildPopup(p) {
    const src = p.source || '';
    const srcFr = { 'data.gouv': 'Registre OFB', 'wayback': 'Registre OFB (archives 2022)', 'fondsvert-p113-2024': 'Fonds vert 2024', 'fondsvert-p113-2025': 'Fonds vert 2025' }[src] || src;
    const v = VERDICT[p.verif_etat];
    const confirme = v && ['confirme_termine', 'confirme_en_cours', 'toujours_a_venir', 'confirme_date'].includes(p.verif_etat);
    const verifNote = v
        ? `<br><b style="color:${v.color}">${v.label}</b>` +
        (p.verif_note ? `<br><small>${p.verif_note}</small>` : '') +
        (p.verif_lien ? `<br><small><a href="${p.verif_lien.replace(/"/g, '&quot;')}" target="_blank" rel="noopener">Lien source</a></small>` : '')
        : '';
    const info = [
        `<b>${p.nom}</b><br>${p.commune}${p.departement ? ' (' + p.departement + ')' : ''}<br>`,
        `Structure : ${p.structure_porteuse || '—'}<br>Année : ${p.annee_debut ? (p.annee_fin ? p.annee_debut + '–' + p.annee_fin : p.annee_debut) : '—'}<br>`,
        `<b>${p.categorie}</b>`,
        !confirme && p.potentiellement_termine ? `<br><b style="color:#8d6e63">⚠ Potentiellement terminé</b><br><small>Début ${p.annee_debut}, durée d'un ABC ≈ 3 ans</small>` : '',
        !confirme && p.potentiellement_en_cours ? `<br><b style="color:#b45309">⏳ Potentiellement en cours</b><br><small>Début annoncé ${p.annee_debut}, toujours « va débuter »</small>` : '',
        !confirme && p.donnees_2022 ? `<br><b style="color:#7f1d1d">🕓 Statut issu des archives 2022</b><br><small>À vérifier — site officiel hors ligne</small>` : '',
        !confirme && p.estime_termine ? `<br><b style="color:#1b5e20">✓ Terminé (estimation)</b><br><small>Statut officiel inconnu, projet débuté en ${p.annee_debut} (> 5 ans)</small>` : '',
        p.anomalie ? `<br><b style="color:#b91c1c">⚠ Commune incohérente</b><br><small>À ${Math.round(p.distance_km)} km de son groupe — non reliée, à vérifier</small>` : '',
        verifNote,
        `<br><small>${p.verifie ? 'Source initiale' : 'Source'} : ${srcFr}</small>`,
        delBtn(p),
    ].join('');
    const sugg =
        `<div class="pop-contrib" data-sugg data-pid="${p.projet_id}">` +
        `<button class="contrib-toggle" data-suggest role="button">💡 Signaler une correction</button>` +
        `<div class="contrib-form" data-sform hidden>` +
        `<select data-s-type class="form-select form-select-sm">` +
        `<option value="statut">Statut</option><option value="date_debut">Date de début</option><option value="note">Note</option><option value="lien">Lien</option><option value="autre">Autre info</option>` +
        `</select>` +
        `<select data-s-statut class="form-select form-select-sm" hidden>${STATUT_SUGG.map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>` +
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
    return info + sugg;
}

function renderFeature(f, onLayer) {
    const p = f.properties;
    const [lon, lat] = f.geometry.coordinates;
    const m = L.circleMarker([lat, lon], {
        radius: 6,
        color: p.anomalie ? '#b91c1c' : p.verifie ? '#14532d' : '#fff',
        weight: p.anomalie || p.verifie ? 2 : 1,
        fillColor: COLOR[dStatut(p)] || COLOR.inconnu,
        fillOpacity: 0.9,
    }).bindPopup(buildPopup(f.properties)).addTo(onLayer || layer);
    m.bindTooltip(`<b>${p.commune}</b>`, { direction: 'top', offset: [0, -8], className: 'map-tooltip' });
    m.on('mouseover', function () { this.setStyle({ radius: 8, weight: 3 }); });
    m.on('mouseout', function () { this.setStyle({ radius: 6, weight: p.anomalie || p.verifie ? 2 : 1 }); });
}

function animateProp(obj, prop, from, to, duration, done) {
    const start = performance.now();
    function tick() {
        const t = Math.min((performance.now() - start) / duration, 1);
        obj.setStyle({ [prop]: from + (to - from) * t });
        if (t < 1) requestAnimationFrame(tick);
        else if (done) done();
    }
    tick();
}

const expandedLayers = new Map();
const centroidMarkers = new Map();
const projectData = new Map();
const aggregateExpanded = new Map();
const aggregateData = new Map();

function clearExpanded() {
    for (const [, grp] of expandedLayers) map.removeLayer(grp);
    expandedLayers.clear();
    centroidMarkers.clear();
    projectData.clear();
    for (const [, grp] of aggregateExpanded) map.removeLayer(grp);
    aggregateExpanded.clear();
    aggregateData.clear();
}

function toggleGroup(pid) {
    const data = projectData.get(pid);
    if (!data) return;
    const { valides, clat, clon } = data;
    const centroid = centroidMarkers.get(pid);
    if (!centroid) return;

    if (expandedLayers.has(pid)) {
        const grp = expandedLayers.get(pid);
        const layers = grp.getLayers();
        let done = 0;
        const onDone = () => { done++; if (done >= layers.length) { map.removeLayer(grp); expandedLayers.delete(pid); } };
        for (const item of layers) {
            if (item instanceof L.Polyline) animateProp(item, 'opacity', 0.65, 0, 200, onDone);
            else animateProp(item, 'fillOpacity', 0.9, 0, 200, onDone);
        }
        animateProp(centroid, 'radius', 5, 10, 250);
    } else {
        animateProp(centroid, 'radius', 10, 5, 250);
        const grp = L.layerGroup().addTo(map);
        expandedLayers.set(pid, grp);
        for (const f of valides) {
            const [lon, lat] = f.geometry.coordinates;
            L.polyline([[clat, clon], [lat, lon]], {
                color: COLOR[dStatut(f.properties)] || COLOR.inconnu, weight: 2, opacity: 0,
            }).addTo(grp);
            renderFeature(f, grp);
        }
        centroid.bringToFront();
        grp.eachLayer(item => { if (!(item instanceof L.Polyline)) item.setStyle({ fillOpacity: 0 }); });
        requestAnimationFrame(() => {
            const items = grp.getLayers();
            const stagger = items.length > 1 ? Math.min(40, 2250 / (items.length - 1)) : 0;
            items.forEach((item, i) => {
                setTimeout(() => {
                    if (item instanceof L.Polyline) animateProp(item, 'opacity', 0, 0.65, 250);
                    else animateProp(item, 'fillOpacity', 0, 0.9, 250);
                }, i * stagger);
            });
        });
    }
}

function toggleAggregate(key) {
    const data = aggregateData.get(key);
    if (!data) return;
    const { byCoord, clat, clon, marker, baseRadius } = data;

    if (aggregateExpanded.has(key)) {
        const grp = aggregateExpanded.get(key);
        const layers = grp.getLayers();
        let done = 0;
        const onDone = () => { done++; if (done >= layers.length) { map.removeLayer(grp); aggregateExpanded.delete(key); } };
        for (const item of layers) {
            if (item instanceof L.Polyline) animateProp(item, 'opacity', 0.65, 0, 200, onDone);
            else animateProp(item, 'fillOpacity', 0.9, 0, 200, onDone);
        }
        animateProp(marker, 'radius', 5, baseRadius, 250);
    } else {
        animateProp(marker, 'radius', baseRadius, 5, 250);
        const grp = L.layerGroup().addTo(map);
        aggregateExpanded.set(key, grp);
        for (const [, feats] of byCoord) {
            const c = feats[0].geometry.coordinates;
            const p = feats[0].properties;
            const live = feats.find(f => f.properties.source !== 'wayback' && !f.properties.donnees_2022);
            const color = COLOR[dStatut(live ? live.properties : p)] || COLOR.inconnu;
            L.polyline([[clat, clon], [c[1], c[0]]], {
                color, weight: 2, opacity: 0,
            }).addTo(grp);
            const pt = L.circleMarker([c[1], c[0]], {
                radius: 6, color: '#fff', weight: 1.5,
                fillColor: color, fillOpacity: 0.9,
            }).addTo(grp);
            pt.bindTooltip(`<b>${p.commune}</b>`, { direction: 'top', offset: [0, -8], className: 'map-tooltip' });
        }
        marker.bringToFront();
        grp.eachLayer(item => { if (item instanceof L.Polyline) item.setStyle({ opacity: 0 }); else item.setStyle({ fillOpacity: 0 }); });
        requestAnimationFrame(() => {
            const items = grp.getLayers();
            const stagger = items.length > 1 ? Math.min(40, 2250 / (items.length - 1)) : 0;
            items.forEach((item, i) => {
                setTimeout(() => {
                    if (item instanceof L.Polyline) animateProp(item, 'opacity', 0, 0.65, 250);
                    else animateProp(item, 'fillOpacity', 0, 0.9, 250);
                }, i * stagger);
            });
        });
    }
}

function fmtCount(n, s) { return n + ' ' + (n > 1 ? s : s.replace(/s$/, '')); }

const STATUT_LABEL = { en_cours: 'En cours', a_venir: 'Va débuter', termine: 'Terminé', inconnu: 'Statut inconnu' };

// Marqueur agrégé : quand plusieurs ABSC distincts partagent le même point
// (ex. deux démarches sur la même commune, ou deux sources du même territoire),
// on affiche un seul marqueur dont le popup liste chaque projet avec son propre
// statut / année / source / verdict.
function buildMultiMarker(group) {
    const lat = group[0].lat;
    const lon = group[0].lon;

    const featsAll = group.flatMap(e => e.feats);
    const distinctCommuneCodes = new Set(featsAll.map(f => f.properties.code_commune).filter(Boolean));
    const nbCommunes = distinctCommuneCodes.size;
    const count = group.length;

    const majorityColor = (() => {
        const tally = {};
        for (const e of group) {
            const s = dStatut(e.feats[0].properties);
            tally[s] = (tally[s] || 0) + 1;
        }
        const top = Object.entries(tally).sort((a, b) => b[1] - a[1])[0][0];
        return COLOR[top] || COLOR.inconnu;
    })();

    const rows = group.map(e => e.feats[0].properties).map(p => {
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
            delBtn(p) +
            `</div>`;
    }).join('');

    const header =
        `<div class="multi-head"><span class="multi-count">${count}</span> ABC sur ce territoire` +
        (nbCommunes ? ` · ${nbCommunes} commune${nbCommunes > 1 ? 's' : ''}` : '') +
        (props.isAdmin ? '<div class="multi-adminnote">Admin : 🗑 supprime un projet jugé erroné.</div>' : '') +
        `</div>`;

    const marker = L.circleMarker([lat, lon], {
        radius: 7 + Math.min(count, 4) * 0.4,
        color: '#fff', weight: 2,
        fillColor: majorityColor, fillOpacity: 0.95,
    });
    const firstCommune = group[0].feats[0].properties.commune;
    marker.bindTooltip(`<b>${count} ABC</b>${nbCommunes && count < nbCommunes ? ' · ' + nbCommunes + ' communes' : (firstCommune ? ' à ' + firstCommune : '')}`, { direction: 'top', offset: [0, -14], className: 'map-tooltip' });
    marker.bindPopup(
        header + rows +
        `<div class="multi-note">Chaque entrée conserve son propre statut et sa source. Corriger via <a href="/verify" target="_blank">la page de vérification</a>.</div>`
    );
    marker.on('mouseover', function () { this.setStyle({ weight: 4 }); });
    marker.on('mouseout', function () { this.setStyle({ weight: 3 }); });
    return marker;
}

function apply() {
    if (!map) return [];
    clearExpanded();
    layer.clearLayers();
    const shown = [];
    const shownProjIds = new Set();

    const projMap = new Map();
    for (const f of all) {
        if (!visible(f, filters)) continue;
        const pid = f.properties.projet_id;
        if (!projMap.has(pid)) projMap.set(pid, []);
        projMap.get(pid).push(f);
        shownProjIds.add(pid);
    }

    const entries = [];

    for (const [pid, feats] of projMap) {
        const anomalieFeats = feats.filter(f => f.properties.anomalie);
        const valides = feats.filter(f => !f.properties.anomalie);

        for (const f of anomalieFeats) {
            entries.push({ lat: f.geometry.coordinates[1], lon: f.geometry.coordinates[0], feats: [f], pid, anomalie: true });
        }
        if (valides.length === 0) continue;
        if (valides.length > 1) {
            const clat = valides.reduce((sum, f) => sum + f.geometry.coordinates[1], 0) / valides.length;
            const clon = valides.reduce((sum, f) => sum + f.geometry.coordinates[0], 0) / valides.length;
            entries.push({ lat: clat, lon: clon, feats: valides, pid });
        } else {
            const f2 = valides[0];
            entries.push({ lat: f2.geometry.coordinates[1], lon: f2.geometry.coordinates[0], feats: [f2], pid });
        }
    }

    const byPoint = new Map();
    for (const e of entries) {
        const key = e.lat.toFixed(5) + '_' + e.lon.toFixed(5);
        if (!byPoint.has(key)) byPoint.set(key, []);
        byPoint.get(key).push(e);
    }

    for (const [pointKey, group] of byPoint) {
        const distinctPids = new Set(group.map(e => e.pid));
        if (group.length > 1 && distinctPids.size > 1) {
            const count = group.length;
            const marker = buildMultiMarker(group).addTo(layer);
            shown.push([group[0].lat, group[0].lon]);

            const byCoord = new Map();
            for (const e of group) {
                for (const f of e.feats) {
                    const c = f.geometry.coordinates;
                    const k = c[0].toFixed(5) + '_' + c[1].toFixed(5);
                    if (!byCoord.has(k)) byCoord.set(k, []);
                    byCoord.get(k).push(f);
                }
            }
            if (byCoord.size > 1) {
                const baseRadius = 7 + Math.min(count, 4) * 0.4;
                aggregateData.set(pointKey, {
                    byCoord,
                    clat: group[0].lat,
                    clon: group[0].lon,
                    marker,
                    baseRadius,
                });
                marker.on('click', () => toggleAggregate(pointKey));
            }
            continue;
        }
        for (const e of group) {
            if (e.feats.length > 1) {
                const st = dStatut(e.feats[0].properties);

                const centroid = L.circleMarker([e.lat, e.lon], {
                    radius: 10, color: '#fff', weight: 2.5,
                    fillColor: COLOR[st] || COLOR.inconnu, fillOpacity: 0.9,
                }).addTo(layer);
                centroid.bindTooltip(
                    `<b>${e.feats[0].properties.nom}</b> — ${e.feats.length} communes`,
                    { direction: 'top', offset: [0, -12], className: 'map-tooltip' }
                );
                const nomListe = e.feats.map(f => f.properties.commune);
                const popupCommunes = nomListe.length > 15
                    ? nomListe.slice(0, 15).join(', ') + `, … et ${nomListe.length - 15} autres`
                    : nomListe.join(', ');
                centroid.bindPopup(
                    `<b>${e.feats[0].properties.nom}</b><br>` +
                    `${e.feats.length} communes : ${popupCommunes}<br>` +
                    `<small>Cliquez pour voir les communes</small>`
                );
                centroid.on('click', () => toggleGroup(e.pid));
                centroid.on('mouseover', function () { this.setStyle({ radius: 13, weight: 3.5 }); });
                centroid.on('mouseout', function () { this.setStyle({ radius: 10, weight: 2.5 }); });

                centroidMarkers.set(e.pid, centroid);
                projectData.set(e.pid, { valides: e.feats, clat: e.lat, clon: e.lon });
                shown.push([e.lat, e.lon]);
            } else {
                for (const f of e.feats) {
                    renderFeature(f);
                    shown.push([f.geometry.coordinates[1], f.geometry.coordinates[0]]);
                }
            }
        }
    }

    countLine.value =
        fmtCount(shown.length, 'points') + ' / ' +
        fmtCount(all.length, 'points') + ' · ' +
        fmtCount(shownProjIds.size, 'projets') + ' / ' +
        fmtCount(allProjs.size, 'projets');
    return shown;
}

function updateCounts() {
    if (!map) return;
    const s = filters;
    const cnts = { en_cours: 0, a_venir: 0, termine: 0, inconnu: 0 };
    const projVisible = new Set();
    let vVerif = 0, vEstime = 0, vPt = 0, vPec = 0, vStale = 0, vAno = 0;
    for (const f of all) {
        if (!visibleIn(f, s)) continue;
        const p = f.properties;
        projVisible.add(p.projet_id);
        cnts[dStatut(p)] = (cnts[dStatut(p)] || 0) + 1;
        if (p.verifie) vVerif++;
        if (p.estime_termine) vEstime++;
        if (p.potentiellement_termine) vPt++;
        if (p.potentiellement_en_cours) vPec++;
        if (p.donnees_2022) vStale++;
        if (p.anomalie) vAno++;
    }
    counts.en_cours = cnts['en_cours'] || 0;
    counts.a_venir = cnts['a_venir'] || 0;
    counts.termine = cnts['termine'] || 0;
    counts.inconnu = cnts['inconnu'] || 0;
    counts.projets = projVisible.size;
    counts.verif = vVerif;
    counts.estime = vEstime;
    counts.pt = vPt;
    counts.pec = vPec;
    counts.stale = vStale;
    counts.ano = vAno;
}

function setTileLayer(idx) {
    if (tileLayer) tileLayer.remove();
    const t = tiles[idx];
    tileLayer = L.tileLayer(t.url, { maxZoom: 18, updateWhenIdle: true, attribution: t.attr }).addTo(map);
    tileIndex.value = idx;
}

function selectTile(i) {
    setTileLayer(i);
    tileOpen.value = false;
}

function toggleTile() {
    exportOpen.value = false;
    tileOpen.value = !tileOpen.value;
}

function toggleExport() {
    tileOpen.value = false;
    exportOpen.value = !exportOpen.value;
}

function onDocPopupClick() {
    tileOpen.value = false;
    exportOpen.value = false;
}

function fitView() {
    const pts = apply();
    if (pts.length === 0) return;
    const lats = pts.map(p => p[0]);
    const lons = pts.map(p => p[1]);
    const spanLon = Math.max(...lons) - Math.min(...lons);
    const spanLat = Math.max(...lats) - Math.min(...lats);
    if (spanLon > 20 || spanLat > 15) {
        map.fitBounds(METRO, { padding: [20, 20] });
        return;
    }
    if (pts.length === 1) map.setView(pts[0], 12);
    else map.fitBounds(pts, { padding: [30, 30] });
}

function onStatutChange() {
    clearExpanded();
    apply();
    updateCounts();
}

function onRegionChange() {
    apply();
    updateCounts();
}

function onYearChange() {
    apply();
    updateCounts();
}

let debounce;
function onSearchInput() {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        const pts = apply();
        updateCounts();
        if (pts.length === 0) return;
        if (pts.length === 1) map.setView(pts[0], 12);
        else map.fitBounds(pts, { padding: [40, 40] });
    }, 200);
}

function showSuggFields(root, t) {
    root.querySelector('[data-s-statut]').hidden = t !== 'statut';
    root.querySelector('[data-s-date]').hidden = t !== 'date_debut';
    root.querySelector('[data-s-note]').hidden = t !== 'note';
    root.querySelector('[data-s-lien]').hidden = t !== 'lien';
    root.querySelector('[data-s-texte]').hidden = t !== 'autre';
}

async function onDocClick(e) {
    const del = e.target.closest('[data-del-pid]');
    if (del) {
        const pid = del.dataset.delPid;
        const nom = del.dataset.delNom || pid;
        if (!window.confirm(`Supprimer le projet « ${nom} » (${pid}) ?\nSa fiche (communes, vérifications, contributions) sera supprimée ET exclue du prochain collect.`)) return;
        const motif = window.prompt('Motif (optionnel, pour le journal d\'audit) :');
        const btn = del;
        btn.disabled = true;
        try {
            await axios.delete(`/api/admin/projets/${encodeURIComponent(pid)}`, { data: { motif: motif || null } });
            await loadData();
            map.closePopup();
        } catch (err) {
            const msg = err.response && err.response.data && err.response.data.error
                ? err.response.data.error
                : (err.response && err.response.status === 401 ? 'Connexion admin requise.' : err.message);
            window.alert('Erreur lors de la suppression : ' + msg);
            btn.disabled = false;
        }
        return;
    }
    const suggest = e.target.closest('[data-suggest]');
    if (suggest) {
        const root = suggest.closest('[data-sugg]');
        const form = root.querySelector('[data-sform]');
        form.hidden = !form.hidden;
        showSuggFields(root, form.querySelector('[data-s-type]').value);
        return;
    }
    const typeSel = e.target.closest('[data-s-type]');
    if (typeSel) {
        showSuggFields(typeSel.closest('[data-sugg]'), typeSel.value);
        return;
    }
    const send = e.target.closest('[data-send]');
    if (send) {
        const root = send.closest('[data-sugg]');
        const t = root.querySelector('[data-s-type]').value;
        const body = { projet_id: root.dataset.pid, type: t, commentaire: root.querySelector('[data-s-commentaire]').value || undefined };
        if (t === 'statut') body.statut_suggere = root.querySelector('[data-s-statut]').value;
        if (t === 'date_debut') {
            const an = Number(root.querySelector('[data-s-annee]').value);
            if (!an) { root.querySelector('[data-s-result]').textContent = "Veuillez renseigner au moins l'année de début."; return; }
            body.annee_debut_suggeree = an;
            const fin = Number(root.querySelector('[data-s-annee-fin]').value);
            if (fin) body.annee_fin_suggeree = fin;
            const src = root.querySelector('[data-s-source]').value.trim();
            if (src) body.source = src;
        }
        if (t === 'note') body.note = root.querySelector('[data-s-note]').value;
        if (t === 'lien') body.lien = root.querySelector('[data-s-lien]').value;
        if (t === 'autre') body.texte = root.querySelector('[data-s-texte]').value;
        send.disabled = true;
        send.textContent = '…';
        try {
            await axios.post('/api/contributions', body);
            root.querySelector('[data-sform]').hidden = true;
            send.disabled = false;
            send.textContent = 'Envoyer la suggestion';
            root.querySelector('[data-s-result]').textContent = '✓ Merci, suggestion envoyée (en attente de modération)';
        } catch (err) {
            const msg = err.response && err.response.data && err.response.data.error ? err.response.data.error : err.message;
            root.querySelector('[data-s-result]').textContent = 'Erreur : ' + msg;
            send.disabled = false;
            send.textContent = 'Envoyer la suggestion';
        }
    }
}

async function exportMap(format) {
    const legendEl = legendRef.value;
    const sidebarEl = sidebarRef.value;
    const navEl = navRef.value;
    const zoomCtrl = document.querySelector('.leaflet-control-zoom');
    const scaleCtrl = document.querySelector('.leaflet-control-scale');
    const wasRetracted = legendRetracted.value;
    legendRetracted.value = false;
    if (zoomCtrl) zoomCtrl.style.display = 'none';
    navEl.style.visibility = 'hidden';
    if (sidebarEl) sidebarEl.style.visibility = 'hidden';
    const legendHead = legendEl.querySelector('.legend-head');
    const origLegendBg = legendEl.style.background;
    const origLegendMaxW = legendEl.style.maxWidth;
    const origLegendW = legendEl.style.width;
    const origLegendShadow = legendEl.style.boxShadow;
    const origHeadBg = legendHead ? legendHead.style.background : null;
    const origScaleMargin = scaleCtrl ? scaleCtrl.style.marginLeft : null;
    if (scaleCtrl) scaleCtrl.style.marginLeft = '400px';
    legendEl.style.background = '#fff';
    legendEl.style.maxWidth = 'none';
    legendEl.style.width = '280px';
    legendEl.style.boxShadow = 'none';
    if (legendHead) legendHead.style.background = '#fff';
    exporting.value = true;
    try {
        await new Promise(r => requestAnimationFrame(r));
        const canvas = await html2canvas(mapRef.value, {
            useCORS: true, backgroundColor: '#fff', scale: 1, logging: false,
        });
        if (format === 'png') {
            const link = document.createElement('a');
            link.download = 'carte-abc.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        } else if (format === 'pdf') {
            const pdf = new jsPDF('landscape', 'mm', 'a4');
            const pw = pdf.internal.pageSize.getWidth();
            const ph = pdf.internal.pageSize.getHeight();
            const r = Math.min(pw / canvas.width, ph / canvas.height);
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', (pw - canvas.width * r) / 2, (ph - canvas.height * r) / 2, canvas.width * r, canvas.height * r);
            pdf.save('carte-abc.pdf');
        }
    } finally {
        exporting.value = false;
        if (zoomCtrl) zoomCtrl.style.display = '';
        if (scaleCtrl) scaleCtrl.style.marginLeft = origScaleMargin;
        navEl.style.visibility = '';
        if (sidebarEl) sidebarEl.style.visibility = '';
        legendEl.style.background = origLegendBg;
        legendEl.style.maxWidth = origLegendMaxW;
        legendEl.style.width = origLegendW;
        legendEl.style.boxShadow = origLegendShadow;
        if (legendHead) legendHead.style.background = origHeadBg;
        if (wasRetracted) legendRetracted.value = true;
    }
}

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

onMounted(async () => {
    const [{ default: leaf }, { default: hc }, jspdfMod] = await Promise.all([
        import('leaflet'),
        import('html2canvas'),
        import('jspdf'),
    ]);
    L = leaf;
    html2canvas = hc;
    jsPDF = jspdfMod.jsPDF;

    map = L.map(mapRef.value, {
        preferCanvas: true,
        zoomSnap: 0.5,
        wheelPxPerZoomLevel: 300,
        scrollWheelZoom: true,
    }).setView([46.5, 2.5], 6);
    L.control.scale({ imperial: false, maxWidth: 160 }).addTo(map);
    setTileLayer(0);
    layer = L.layerGroup().addTo(map);
    map.on('moveend', updateCounts);
    document.addEventListener('click', onDocPopupClick);
    document.addEventListener('click', onDocClick);

    const m = props.meta || {};
    counts.projets = m.countProjets || 0;
    counts.verif = m.countVerifies || 0;
    counts.estime = m.countEstimes || 0;
    counts.pt = m.countPotentiellementTermines || 0;
    counts.pec = m.countPotentiellementEnCours || 0;
    counts.stale = m.countDonnees2022 || 0;
    counts.ano = m.countAnomalies || 0;
    suggBadge.value = m.countContributionsEnAttente || 0;

    loadData();
});

async function loadData() {
    try {
        const r = await axios.get('/api/abc.geojson');
        all = r.data.features;
        allProjs = new Set(all.map(f => f.properties.projet_id));
        regionOptions.value = [...new Set(all.map(f => f.properties.region).filter(Boolean))].sort();
        yearOptions.value = [...new Set(all.map(f => f.properties.annee_debut).filter(Boolean))].sort();
        apply();
        updateCounts();
    } catch (e) {
        countLine.value = 'Erreur de chargement';
        console.error(e);
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocPopupClick);
    document.removeEventListener('click', onDocClick);
    if (map) map.remove();
});
</script>

<template>
    <div class="d-flex flex-column">
        <Seo
            description="Carte des Atlas de la Biodiversité Communale (ABC) en France : suivi des projets financés (Registre OFB, Fonds vert), statuts, communes et porteurs — départements et régions."
            :jsonLd="seoJsonLd"
        />
        <nav ref="navRef" class="navbar navbar-dark navbar-expand-lg navbar-abc flex-shrink-0 py-2">
            <div class="container-fluid">
                <span class="navbar-brand fs-6 d-flex align-items-center gap-2 mb-0">🌿 Observatoire des ABC</span>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#filters" aria-controls="filters" aria-expanded="false" aria-label="Filtres">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="filters">
                    <div class="d-flex flex-wrap align-items-center gap-2 text-white ms-lg-3 mt-2 mt-lg-0 w-100 justify-content-start">
                        <label class="d-flex align-items-center gap-1" style="font-size:13px;white-space:nowrap">
                            <span class="text-white-50">Statut</span>
                            <select v-model="filters.statut" class="form-select form-select-sm w-auto" @change="onStatutChange">
                                <option value="">Tous</option>
                                <option value="en_cours">En cours</option>
                                <option value="a_venir">Va débuter</option>
                                <option value="termine">Terminé</option>
                                <option value="inconnu">Statut inconnu</option>
                            </select>
                        </label>
                        <label class="d-flex align-items-center gap-1" style="font-size:13px;white-space:nowrap">
                            <span class="text-white-50">Région</span>
                            <select v-model="filters.region" class="form-select form-select-sm w-auto" @change="onRegionChange">
                                <option value="">Toutes</option>
                                <option v-for="r in regionOptions" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </label>
                        <label class="d-flex align-items-center gap-1" style="font-size:13px;white-space:nowrap">
                            <span class="text-white-50">Période</span>
                            <select v-model="filters.yearFrom" class="form-select form-select-sm w-auto" title="Année de début minimum" @change="onYearChange">
                                <option value="">De 🔽</option>
                                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                            </select>
                            <span class="text-white-50">–</span>
                            <select v-model="filters.yearTo" class="form-select form-select-sm w-auto" title="Année de début maximum" @change="onYearChange">
                                <option value="">À 🔽</option>
                                <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                            </select>
                        </label>
                        <label class="d-flex align-items-center gap-1" style="font-size:13px">
                            <span class="text-white-50">Commune</span>
                            <input v-model="filters.q" type="text" class="form-control form-control-sm" style="width:180px" placeholder="Rechercher…" @input="onSearchInput" />
                        </label>
                        <span class="badge text-bg-light mt-1 mt-sm-0 ms-lg-auto ms-0">{{ countLine }}</span>
                        <span class="count-help-wrap mt-1 mt-sm-0" tabindex="0">
                            <span class="count-help">?</span>
                            <span class="count-help-tip">
                                <b>Comptage des points</b>
                                <p>Les compteurs totaux (bas) et par statut (légende) ne tiennent compte que des projets visibles dans la fenêtre courante de la carte, filtres appliqués.</p>
                                <p>Projets regroupés en un point rempli sont comptés pour leurs communes.</p>
                                <p>Quand plusieurs ABC cohabitent à la même commune, un marqueur unique liste chaque projet avec son propre statut et son année.</p>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </nav>

        <div id="map" ref="mapRef" class="map-main">
            <div class="legend" :class="{ retracted: legendRetracted }" ref="legendRef">
                <div class="legend-head" role="button" tabindex="0" title="Afficher / masquer" @click="legendRetracted = !legendRetracted">
                    <span class="title">Légende</span><span class="chev">▾</span>
                </div>
                <div class="legend-body" v-show="!legendRetracted">
                    <div class="legend-section">Statuts</div>
                    <div class="legend-item"><span class="chip" style="background:var(--ok)"></span>En cours<span class="cnt">{{ counts.en_cours }}</span></div>
                    <div class="legend-item"><span class="chip" style="background:var(--debut)"></span>Va débuter<span class="cnt">{{ counts.a_venir }}</span></div>
                    <div class="legend-item"><span class="chip" style="background:var(--fini)"></span>Terminé<span class="cnt">{{ counts.termine }}</span></div>
                    <div class="legend-item"><span class="chip" style="background:var(--inconnu)"></span>Statut inconnu<span class="cnt">{{ counts.inconnu }}</span></div>
                    <div class="legend-section">Données</div>
                    <div class="meta">
                        <div class="meta-row"><span class="k">Projets</span><span class="v">{{ counts.projets }} <small class="text-body-secondary">visibles</small></span></div>
                        <div v-if="counts.verif" class="meta-row"><span class="k">Vérifiés manuellement</span><span class="v">✓ {{ counts.verif }}</span></div>
                        <div v-if="counts.estime" class="meta-row"><span class="k">Reclassés Terminé (&gt; 5 ans)</span><span class="v">{{ counts.estime }}</span></div>
                        <div v-if="counts.pt" class="meta-row"><span class="k">Potentiellement terminés</span><span class="v">{{ counts.pt }}</span></div>
                        <div v-if="counts.pec" class="meta-row"><span class="k">Potentiellement en cours</span><span class="v">{{ counts.pec }}</span></div>
                        <div v-if="counts.stale" class="meta-row"><span class="k">Figés aux archives 2022</span><span class="v">{{ counts.stale }}</span></div>
                        <div v-if="counts.ano" class="meta-row"><span class="k">Communes incohérentes écartées</span><span class="v">{{ counts.ano }}</span></div>
                        <div class="legend-item" style="padding-top:6px;font-weight:600;color:#374151">Mise à jour des sources</div>
                        <div class="meta-note"><span class="k">Registre OFB</span><span class="v">maj {{ fmtDate(meta?.sources?.['data.gouv']) }}</span></div>
                        <div class="meta-note"><span class="k">Archives 2022</span><span class="v">maj {{ fmtDate(meta?.sources?.wayback) }}</span></div>
                        <div class="meta-note"><span class="k">Fonds vert 2024</span><span class="v">maj {{ fmtDate(meta?.sources?.['fondsvert-p113-2024']) }}</span></div>
                        <div class="meta-note"><span class="k">Fonds vert 2025</span><span class="v">maj {{ fmtDate(meta?.sources?.['fondsvert-p113-2025']) }}</span></div>
                    </div>
                </div>
            </div>

            <div class="map-sidebar" ref="sidebarRef">
                <div class="map-sidebar-btn" role="button" tabindex="0" title="Fond de carte" @click.stop="toggleTile">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                    <span class="tip">Fond de carte</span>
                    <div class="map-sidebar-popup" :class="{ open: tileOpen }">
                        <button v-for="(t, i) in tiles" :key="t.name" class="map-sidebar-popup-item" :class="{ active: tileIndex === i }" @click.stop="selectTile(i)">{{ t.name }}</button>
                    </div>
                </div>
                <button class="map-sidebar-btn" title="Ajuster la vue aux points affichés" @click="fitView">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                    <span class="tip">Recentrer</span>
                </button>
                <div class="map-sidebar-btn" role="button" tabindex="0" title="Exporter la carte" @click.stop="toggleExport">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span class="tip">Exporter</span>
                    <div class="map-sidebar-popup" :class="{ open: exportOpen }">
                        <button class="map-sidebar-popup-item" @click.stop="exportMap('png')">PNG</button>
                        <button class="map-sidebar-popup-item" @click.stop="exportMap('pdf')">PDF</button>
                    </div>
                </div>
                <a class="map-sidebar-btn" href="/verify" title="Liste des projets à vérifier">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span class="tip">Vérifier</span>
                    <span v-if="suggBadge" class="map-sidebar-badge">{{ suggBadge }}</span>
                </a>
            </div>

            <div class="map-north">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 3 5 22 12 16 19 22 12 3" fill="#dc2626"/></svg>
                <span style="font-size:15px">N</span>
            </div>
        </div>

        <section class="home-content">
            <div class="container py-5">
                <div class="row">
                    <div class="col-lg-8">
                        <h1>Observatoire des ABC — Atlas de la Biodiversité Communale</h1>
                        <p>
                            L'Observatoire des ABC est le suivi national des <strong>Atlas de la Biodiversité Communale</strong>,
                            démarches financées par l'État (Registre OFB et Fonds vert) pour connaître la biodiversité
                            d'une commune et la faire découvrir à ses habitants. La carte ci-dessus recense
                            <strong>{{ meta?.countProjets ?? 0 }} projets</strong> sur
                            <strong>{{ meta?.countCommunes ?? 'plus de 5 400' }} communes</strong>,
                            avec leur statut (en cours, va débuter, terminé), leur porteur et leurs périodes.
                        </p>
                        <p>
                            Chaque projet est documenté depuis les sources publiques (data.gouv.fr) puis vérifié manuellement
                            quand nécessaire : les données publiées ici réutilisent l'attribution de
                            <a :href="site?.sources?.dataGouv || 'https://data.gouv.fr'">leurs producteurs</a>.
                        </p>
                        <div class="home-stats">
                            <div class="stat"><span class="n">{{ meta?.countProjets ?? 0 }}</span><span class="l">projets suivis</span></div>
                            <div class="stat"><span class="n">✓ {{ meta?.countVerifies ?? 0 }}</span><span class="l">projets vérifiés manuellement</span></div>
                            <div class="stat"><span class="n">{{ meta?.countEstimes ?? 0 }}</span><span class="l">reclassés « terminé » (&gt; 5 ans)</span></div>
                            <div class="stat"><span class="n">{{ meta?.countAnomalies ?? 0 }}</span><span class="l">communes incohérentes écartées</span></div>
                        </div>
                        <p class="mt-3 mb-0">
                            <Link class="btn btn-outline-success btn-sm" href="/actualites">Suivre l'actualité de l'observatoire</Link>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/verify">Voir la page de vérification</Link>
                        </p>
                    </div>
                    <div class="col-lg-4">
                        <h2 class="h5">Explorer par région</h2>
                        <ul class="list-unstyled home-links">
                            <li v-for="r in index?.regions" :key="r.slug">
                                <Link :href="`/region/${r.slug}`">{{ r.label }} <small>({{ r.n }})</small></Link>
                            </li>
                        </ul>
                        <h2 class="h5 mt-4">Explorer par département</h2>
                        <ul class="list-unstyled home-links">
                            <li v-for="d in index?.departements" :key="d.code">
                                <Link :href="`/departement/${d.code}`">{{ d.label }} <small>({{ d.n }})</small></Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="container">
                <div class="home-sources mb-2">
                    <h2 class="h6">Les données</h2>
                    <p class="small text-muted mb-0">{{ site?.licenseNote }}</p>
                    <ul class="small text-muted mb-0">
                        <li>Registre OFB — Atlas de la Biodiversité Communale, mis à jour le {{ fmtSourceDate(meta?.sources?.['data.gouv']) }}</li>
                        <li>Fonds vert (P113) — liste des projets subventionnés</li>
                        <li>Archives 2022 du registre national ABC</li>
                    </ul>
                </div>
            </div>
        </section>

        <footer class="home-footer py-3">
            <div class="container small text-center text-muted">
                Observatoire des ABC — données reuses en lisant <a href="https://data.gouv.fr" rel="noopener">data.gouv.fr</a>.
                Projet ouvert&nbsp;·
                <Link href="/actualites">Actualités</Link>&nbsp;·
                <Link href="/mentions-legales">Mentions légales</Link>&nbsp;·
                <Link href="/confidentialite">Confidentialité</Link>
            </div>
        </footer>

        <div v-if="exporting" class="export-overlay">
            <div class="spinner-border text-light" role="status"></div>
            <div>Génération de l’image…</div>
        </div>
    </div>
</template>

<style scoped>
.navbar-abc { background: #14532d; }
.map-main {
    position: relative;
    z-index: 0;
    height: 70vh;
    min-height: 460px;
}
.home-content { background: #f8faf9; border-top: 1px solid #e2e8f0; }
.home-content h1 { font-size: 1.7rem; font-weight: 700; color: #14532d; margin-bottom: 1rem; }
.home-content h2 { color: #14532d; }
.home-stats { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1.25rem; }
.home-stats .stat {
    flex: 1 1 130px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.home-stats .stat .n { display: block; font-size: 1.35rem; font-weight: 700; color: #14532d; }
.home-stats .stat .l { display: block; font-size: .8rem; color: #64748b; }
.home-links { column-count: 2; column-gap: 1rem; font-size: .9rem; }
.home-links li { break-inside: avoid; margin-bottom: .25rem; }
.home-links a { color: #14532d; text-decoration: none; }
.home-links a:hover { text-decoration: underline; }
.home-links small { color: #94a3b8; }
.home-sources { padding: 1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.home-footer { background: #14532d; color: #e8f5e9; }
.home-footer a { color: #fff; }
.count-help-wrap { position: relative; display: inline-flex; }
.count-help {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%;
    background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.4);
    font-size: 12px; font-weight: 700; cursor: help;
}
.count-help-tip {
    display: none; position: absolute; right: 0; top: calc(100% + 8px); z-index: 1001;
    width: 360px; max-width: calc(100vw - 24px); padding: 8px 10px; border-radius: 8px;
    background: #1f2937; color: #f3f4f6; font-size: 12px; line-height: 1.5;
    box-shadow: 0 4px 14px rgba(0,0,0,.4); text-align: left; white-space: normal;
}
.count-help-tip b { display: block; margin-bottom: 4px; color: #fff; }
.count-help-tip p { margin: 0 0 6px; }
.count-help-tip p:last-child { margin-bottom: 0; }
.count-help-wrap:hover .count-help-tip, .count-help-wrap:focus .count-help-tip { display: block; }
#map { position: relative; z-index: 0; }
.legend {
    position: absolute; z-index: 1000; bottom: 16px; left: 10px;
    width: 288px; max-width: calc(100vw - 20px);
    font-family: var(--bs-body-font-family);
    background: rgba(255,255,255,.97); border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,.25);
}
.legend-head {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 12px; cursor: pointer; user-select: none;
    background: linear-gradient(180deg,#f8fafc,#eef2f7);
    border-bottom: 1px solid #e2e8f0;
}
.legend-head .title { font-size: 13px; font-weight: 700; color: #1f2937; letter-spacing: .02em; }
.legend-head .chev { margin-left: auto; font-size: 11px; color: #64748b; transition: transform .2s; }
.legend.retracted .chev { transform: rotate(-90deg); }
.legend-body { padding: 10px 12px 12px; }
.legend-section {
    font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #94a3b8; margin: 10px 0 5px;
}
.legend-section:first-child { margin-top: 0; }
.legend-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 12.5px; color: #374151; line-height: 1.5; padding: 2px 0;
}
.legend-item .chip { flex: none; }
.legend-item .cnt { margin-left: auto; font-size: 11.5px; font-weight: 600; color: #9ca3af; font-variant-numeric: tabular-nums; }
.meta { padding-top: 4px; }
.meta-row { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; font-size: 12.5px; padding: 1.5px 0; }
.meta-row .k { color: #4b5563; }
.meta-row .v { font-weight: 600; color: #111827; white-space: nowrap; font-variant-numeric: tabular-nums; }
.meta-note { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; font-size: 11.5px; padding: 1.5px 0; }
.meta-note .k { color: #6b7280; }
.meta-note .v { color: #374151; text-align: right; font-variant-numeric: tabular-nums; }
.chip { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
.leaflet-control-scale { margin-left: 300px !important; }
@media (max-width:720px) {
    .legend { left: 6px; right: 6px; width: auto; max-width: none; }
    .leaflet-control-scale { margin-left: 6px !important; }
}
.map-sidebar {
    position: absolute; z-index: 1000; top: 10px; right: 10px;
    display: flex; flex-direction: column; gap: 4px;
}
.map-sidebar-btn {
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: none; border-radius: 8px;
    background: rgba(255,255,255,.92); color: #374151;
    box-shadow: 0 2px 6px rgba(0,0,0,.2);
    cursor: pointer; position: relative;
}
.map-sidebar-btn:hover { background: #fff; color: #111827; box-shadow: 0 3px 10px rgba(0,0,0,.25); }
.map-sidebar-badge {
    position: absolute; top: -4px; right: -4px;
    min-width: 16px; height: 16px; padding: 0 4px;
    border-radius: 8px; background: #dc2626; color: #fff;
    font-size: 10px; font-weight: 700; line-height: 16px; text-align: center;
}
.map-sidebar-btn .tip {
    position: absolute; right: calc(100% + 8px); top: 50%; transform: translateY(-50%);
    white-space: nowrap; background: #1f2937; color: #fff;
    padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500;
    pointer-events: none; opacity: 0; transition: opacity .15s;
}
.map-sidebar-btn:hover .tip { opacity: 1; }
.map-sidebar-btn .tip::after {
    content: ''; position: absolute; left: 100%; top: 50%; transform: translateY(-50%);
    border: 5px solid transparent; border-left-color: #1f2937;
}
.map-sidebar-popup {
    display: none; position: absolute; right: calc(100% + 8px); top: 0;
    background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.25);
    padding: 4px; min-width: 120px; z-index: 1001;
}
.map-sidebar-popup.open { display: block; }
.map-sidebar-popup-item {
    display: block; width: 100%; padding: 6px 10px; border: none; border-radius: 6px;
    background: none; color: #374151; font-size: 12px; text-align: left; cursor: pointer;
    white-space: nowrap;
}
.map-sidebar-popup-item:hover { background: #f3f4f6; color: #111827; }
.map-sidebar-popup-item.active { background: #14532d; color: #fff; }
.map-north {
    position: absolute; z-index: 1000; top: 84px; left: 12px;
    display: flex; align-items: center; gap: 4px;
    background: rgba(255,255,255,.95); padding: 5px 9px; border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    border: 1px solid rgba(255,255,255,.8);
    color: #1f2937; font-size: 14px; font-weight: 700; line-height: 1;
}
.export-overlay {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: rgba(0,0,0,.55); color: #fff;
}
.export-overlay div:last-child { margin-top: 12px; font-size: 15px; font-weight: 600; }
</style>

<style>
[hidden] { display: none !important; }
html, body { margin: 0; }
:root { --ok: #2e7d32; --debut: #ef6c00; --fini: #1565c0; --inconnu: #9e9e9e; }
.leaflet-popup-content { max-height: 300px; overflow-y: auto; }
.map-tooltip { background: #1f2937; color: #fff; border: none; border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 500; box-shadow: 0 2px 6px rgba(0,0,0,.3); }
.map-tooltip::before { border-top-color: #1f2937; }
.pop-contrib { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0; }
.pop-contrib .contrib-toggle {
    border: 1px solid #cbd5e1; background: #fff; color: #14532d;
    border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 600; cursor: pointer;
}
.pop-contrib .contrib-toggle:hover { background: #f0fdf4; }
.pop-contrib .contrib-form { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; max-width: 260px; }
.pop-contrib .saved { font-size: 12px; font-weight: 600; }
.multi-head { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #111827; flex-wrap: wrap; }
.multi-count {
    flex: none; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
    background: #14532d; color: #fff; font-size: 12px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
}
.multi-adminnote { width: 100%; flex-basis: 100%; font-size: 11px; font-weight: 500; color: #7f1d1d; margin-top: 2px; }
.multi-row { display: flex; align-items: flex-start; gap: 6px; padding: 4px 0; line-height: 1.35; border-top: 1px solid #f1f5f9; }
.multi-row:first-of-type { border-top: none; }
.multi-chip { flex: none; width: 10px; height: 10px; border-radius: 50%; margin-top: 4px; }
.multi-main { flex: 1; min-width: 0; }
.multi-name { font-weight: 600; font-size: 12.5px; color: #111827; }
.multi-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.multi-badge {
    display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 11px;
    border: 1px solid #cbd5e1; color: #334155; background: #f8fafc; white-space: nowrap;
}
.multi-src.src-stale { background: #fff7ed; border-color: #fdba74; color: #9a3412; }
.multi-srcnote { margin-top: 3px; font-size: 11px; color: #b45309; }
.btn-del {
    flex: none; border: 1px solid #fecaca; background: #fff; color: #b91c1c;
    border-radius: 6px; width: 24px; height: 24px; font-size: 13px; line-height: 1;
    cursor: pointer; padding: 0;
}
.btn-del:hover { background: #fef2f2; border-color: #f87171; }
.multi-note { margin-top: 6px; padding-top: 6px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b; }
</style>