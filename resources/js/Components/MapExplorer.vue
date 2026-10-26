<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { COLOR, METRO, PALETTES, applyPalette, dStatut, fmtCount, visible } from '../map/constants.js';
import { buildPopup as buildPopupHtml, buildMultiRow, buildMultiHeader } from '../map/popupBuilders.js';
import { exportMap as exportMapImage } from '../map/exportImage.js';

let L = null;

const props = defineProps({
    meta: {
        type: Object,
        default: null,
    },
    isAdmin: {
        type: Boolean,
        default: false,
    },
    compactLegend: {
        type: Boolean,
        default: false,
    },
    fullPage: {
        type: Boolean,
        default: false,
    },
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
const paletteKey = ref('defaut');
const paletteSwitcher = ref(false);

const tiles = [
    { name: 'Plan', url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', attr: '© OpenStreetMap' },
    { name: 'Satellite', url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', attr: '© Esri' },
];

let map = null;
let tileLayer = null;
let layer = null;
let all = [];
let allProjs = new Set();

function inViewport(f) {
    if (!map) return false;
    const [lon, lat] = f.geometry.coordinates;
    return map.getBounds().contains([lat, lon]);
}

function visibleIn(f, s) {
    return visible(f, s) && inViewport(f);
}

function popupHtml(p) {
    return buildPopupHtml(p, props.isAdmin);
}

function renderFeature(f, onLayer) {
    const p = f.properties;
    const [lon, lat] = f.geometry.coordinates;
    const st = dStatut(p);
    const dashes = st === 'a_venir' && !p.anomalie && !p.verifie;
    const baseWeight = p.anomalie || p.verifie ? 2 : (dashes ? 1.5 : 1);
    const m = L.circleMarker([lat, lon], {
        radius: 6,
        color: p.anomalie ? '#b91c1c' : p.verifie ? '#14532d' : '#fff',
        weight: baseWeight,
        dashArray: dashes ? '2.5,2' : undefined,
        fillColor: COLOR[st] || COLOR.inconnu,
        fillOpacity: 0.9,
    }).bindPopup(popupHtml(f.properties)).addTo(onLayer || layer);
    m.bindTooltip(`<b>${p.commune}</b>`, { direction: 'top', offset: [0, -8], className: 'map-tooltip' });
    m.on('mouseover', function () { this.setStyle({ radius: 8, weight: 3 }); });
    m.on('mouseout', function () { this.setStyle({ radius: 6, weight: baseWeight, dashArray: dashes ? '2.5,2' : undefined }); });
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
                dashArray: dStatut(live ? live.properties : p) === 'a_venir' ? '2.5,2' : undefined,
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

    const rows = group.map(e => buildMultiRow(e.feats[0].properties, props.isAdmin)).join('');
    const header = buildMultiHeader(count, nbCommunes, props.isAdmin);

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
                    dashArray: st === 'a_venir' ? '4,3' : undefined,
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

/** Bascule de palette (test visuel) : COLOR mute en place, on redessine tout. */
function onPaletteChange() {
    paletteKey.value = applyPalette(paletteKey.value);
    const url = new URL(window.location.href);
    url.searchParams.set('palette', paletteKey.value);
    window.history.replaceState(null, '', url);
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
        // Le formulaire de suggestion vit désormais sur /verify (compte requis) :
        // on y redirige directement sur le projet ciblé par la correction.
        e.preventDefault();
        const pid = suggest.closest('[data-sugg]').dataset.pid;
        router.visit('/verify', { data: { projet: pid } });
        return;
    }
}

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

function exportMap(format) {
    return exportMapImage(format, {
        mapEl: mapRef.value,
        navEl: navRef.value,
        sidebarEl: sidebarRef.value,
        legendRetracted,
        exporting,
        leafletMap: map,
    });
}

onMounted(async () => {
    // Palette de test : activée par ?palette=<clé> ou ?palettes=1 (sélecteur seul).
    const paletteParams = new URLSearchParams(window.location.search);
    if (paletteParams.has('palette') || paletteParams.has('palettes')) {
        paletteSwitcher.value = true;
        paletteKey.value = applyPalette(paletteParams.get('palette'));
    }

    const [{ default: leaf }] = await Promise.all([import('leaflet')]);
    L = leaf;

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
    <div class="d-flex flex-column" :class="{ 'map-wrap-full': fullPage }">
        <div ref="navRef" class="map-toolbar">
            <label class="tb-field">
                <span class="tb-label">Statut</span>
                <select v-model="filters.statut" class="tb-ctrl" @change="onStatutChange">
                    <option value="">Tous</option>
                    <option value="en_cours">En cours</option>
                    <option value="a_venir">Va débuter</option>
                    <option value="termine">Terminé</option>
                    <option value="inconnu">Statut inconnu</option>
                </select>
            </label>
            <label class="tb-field">
                <span class="tb-label">Région</span>
                <select v-model="filters.region" class="tb-ctrl" @change="onRegionChange">
                    <option value="">Toutes</option>
                    <option v-for="r in regionOptions" :key="r" :value="r">{{ r }}</option>
                </select>
            </label>
            <label class="tb-field">
                <span class="tb-label">Période</span>
                <select v-model="filters.yearFrom" class="tb-ctrl" title="Année de début minimum" @change="onYearChange">
                    <option value="">De</option>
                    <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                </select>
                <span class="tb-sep">–</span>
                <select v-model="filters.yearTo" class="tb-ctrl" title="Année de début maximum" @change="onYearChange">
                    <option value="">À</option>
                    <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                </select>
            </label>
            <label class="tb-field tb-search">
                <span class="tb-label">Commune</span>
                <input v-model="filters.q" type="text" class="tb-ctrl tb-input" placeholder="Rechercher…" @input="onSearchInput" />
            </label>
            <span class="tb-count">{{ countLine }}</span>
            <span class="count-help-wrap" tabindex="0">
                <span class="count-help">?</span>
                <span class="count-help-tip">
                    <b>Comptage des points</b>
                    <p>Les compteurs totaux (bas) et par statut (légende) ne tiennent compte que des projets visibles dans la fenêtre courante de la carte, filtres appliqués.</p>
                    <p>Projets regroupés en un point rempli sont comptés pour leurs communes.</p>
                    <p>Quand plusieurs ABC cohabitent à la même commune, un marqueur unique liste chaque projet avec son propre statut et son année.</p>
                    <p>Les projets dont aucune commune n'a pu être géolocalisée ne figurent pas sur la carte : le compteur « projets » peut donc être inférieur au total de l'accueil.</p>
                </span>
            </span>
        </div>

        <div id="map" ref="mapRef" class="map-main" :class="{ 'map-full': fullPage, 'map-compact-legend': compactLegend }">
            <div class="legend" :class="{ retracted: legendRetracted }" ref="legendRef">
                <div class="legend-head" role="button" tabindex="0" title="Afficher / masquer" @click="legendRetracted = !legendRetracted">
                    <span class="title">Légende</span><span class="chev"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                </div>
                <div class="legend-body" v-show="!legendRetracted">
                    <div class="legend-section">Statuts</div>
                    <div class="legend-item"><span class="chip" style="background:var(--ok)"></span>En cours<span class="cnt">{{ counts.en_cours }}</span></div>
                    <div class="legend-item"><span class="chip chip-dash" style="border-color:var(--debut)"></span>Va débuter<span class="cnt">{{ counts.a_venir }}</span></div>
                    <div class="legend-item"><span class="chip" style="background:var(--fini)"></span>Terminé<span class="cnt">{{ counts.termine }}</span></div>
                    <div class="legend-item"><span class="chip" style="background:var(--inconnu)"></span>Statut inconnu<span class="cnt">{{ counts.inconnu }}</span></div>
                    <template v-if="paletteSwitcher">
                        <div class="legend-section">Palette (test)</div>
                        <select v-model="paletteKey" class="palette-select" title="Exploration visuelle — palette des statuts" @change="onPaletteChange">
                            <option v-for="(p, k) in PALETTES" :key="k" :value="k">{{ p.label }}</option>
                        </select>
                    </template>
                    <template v-if="!compactLegend">
                        <div class="legend-section">Données</div>
                        <div class="meta">
                            <div class="meta-row"><span class="k">Projets</span><span class="v">{{ counts.projets }} <small class="text-body-secondary">visibles</small></span></div>
                            <div v-if="counts.verif" class="meta-row"><span class="k">Vérifiés manuellement</span><span class="v"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ counts.verif }}</span></div>
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
                    </template>
                </div>
            </div>

            <div class="map-sidebar" ref="sidebarRef">
                <a v-if="fullPage" class="map-sidebar-btn" href="/" title="Retour à l'accueil">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span class="tip">Accueil</span>
                </a>
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

        <div v-if="exporting" class="export-overlay">
            <div class="spinner-border text-light" role="status"></div>
            <div>Génération de l’image…</div>
        </div>
    </div>
</template>

<style scoped>
.map-wrap-full { flex: 1 1 auto; min-height: 0; }
.map-toolbar {
    position: relative; z-index: 1001; flex-shrink: 0;
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    padding: 8px 12px;
    background: #f1f5f9; border-bottom: 1px solid #e2e8f0;
}
.tb-field {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 4px 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
    font-size: 12px; white-space: nowrap;
}
.tb-field:focus-within { border-color: #14532d; box-shadow: 0 0 0 2px rgba(20,83,45,.15); }
.tb-label { color: #6b7280; font-weight: 600; }
.tb-ctrl {
    border: none; background: transparent; padding: 0; width: auto;
    font-size: 12px; font-weight: 500; color: #111827; box-shadow: none;
}
.tb-ctrl:focus { box-shadow: none; }
.tb-input { min-width: 150px; }
.tb-sep { color: #9ca3af; }
.tb-count {
    margin-left: auto;
    background: #14532d; color: #fff; font-weight: 600; font-size: 11.5px;
    padding: 4px 12px; border-radius: 20px; white-space: nowrap;
}
.map-main {
    position: relative;
    z-index: 0;
    height: 70vh;
    min-height: 460px;
}
.map-main.map-full {
    height: auto;
    flex: 1 1 auto;
    min-height: 420px;
}
#map { position: relative; z-index: 0; }
.legend {
    position: absolute; z-index: 1000; bottom: 30px; left: 10px;
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
.chip-dash { background: transparent; border: 1.5px dashed var(--debut); box-sizing: border-box; }
.leaflet-control-scale { margin-left: 300px !important; }
@media (max-width:720px) {
    .legend { left: 6px; right: 6px; width: auto; max-width: none; }
    .legend-head { padding: 7px 10px; }
    .legend-body { padding: 8px 10px 10px; }
    .legend-section { margin: 8px 0 4px; }
    .legend-item { font-size: 12px; padding: 1.5px 0; }
    .map-compact-legend .legend .meta { display: none; }
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
/* Composition appliquée uniquement pendant la capture (classe posée par
   l'export) : légende opaque en bas à gauche, flèche du nord alignée en
   haut à gauche, échelle et attribution dans la bande du bas. */
.map-exporting .legend {
    bottom: 30px; left: 10px; width: 280px; max-width: none;
    background: #fff; box-shadow: none;
}
.map-exporting .legend-head { background: #fff; }
.map-exporting .map-north { top: 10px; }
.export-overlay {
    position: fixed; inset: 0; z-index: 9999;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: rgba(0,0,0,.55); color: #fff;
}
.export-overlay div:last-child { margin-top: 12px; font-size: 15px; font-weight: 600; }
.count-help-wrap { position: relative; display: inline-flex; }
.count-help {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; border-radius: 50%;
    background: #14532d; color: #fff; border: 1px solid #14532d;
    font-size: 12px; font-weight: 700; cursor: help;
}
.count-help:hover { background: #166534; }
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
</style>

<style>
html, body { margin: 0; }
:root { --ok: #2e7d32; --debut: #ef6c00; --fini: #1565c0; --inconnu: #9e9e9e; }
.leaflet-popup-content { max-height: 300px; overflow-y: auto; }
.map-tooltip { background: #1f2937; color: #fff; border: none; border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 500; box-shadow: 0 2px 6px rgba(0,0,0,.3); }
.map-tooltip::before { border-top-color: #1f2937; }
.palette-select { width: 100%; margin: 2px 0 8px; padding: 3px 6px; font-size: 12px; color: #1f2937; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; }
.pop-contrib { margin-top: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0; }
.pop-contrib .contrib-toggle {
    border: 1px solid #cbd5e1; background: #fff; color: #14532d;
    border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 600;
    cursor: pointer; text-decoration: none; display: inline-block;
}
.pop-contrib .contrib-toggle:hover { background: #f0fdf4; color: #14532d; }
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