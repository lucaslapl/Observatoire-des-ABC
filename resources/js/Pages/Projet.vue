<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';
import { COLOR, DEFAULT_PALETTE_KEY, applyPalette } from '../map/constants.js';

const props = defineProps({
    projet: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const crumbs = computed(() => {
    const list = [];
    if (props.projet.region_slug) {
        list.push({ label: props.projet.region_label, url: `/region/${props.projet.region_slug}` });
    }
    list.push({ label: props.projet.nom });
    return list;
});

const statutClass = (s) =>
    ({ en_cours: 'badge-st-en_cours', a_venir: 'badge-st-a_venir', termine: 'badge-st-termine', inconnu: 'badge-st-inconnu' })[s] || 'badge-st-inconnu';

const pageTitle = computed(() => props.projet.nom);
const pageDescription = computed(() => {
    const p = props.projet;
    return `${p.nom} (${p.statut_label})${p.structure_porteuse ? ' — ' + p.structure_porteuse : ''}${p.annee_debut ? ' — depuis ' + p.annee_debut : ''}. ${p.communes.length} commune(s) concernée(s), source ${p.source_label}.`;
});

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const p = props.projet;
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    if (p.region_slug) {
        items.push({ '@type': 'ListItem', position: 2, name: p.region_label, item: `${base}/region/${p.region_slug}` });
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: p.nom });

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: items,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Project',
            name: p.nom,
            url: `${base}/abc/${p.slug}`,
            description: `${p.nom} — Atlas de la Biodiversité Communale (${p.statut_label})`,
            identifier: p.id,
            ...(p.annee_debut ? { startDate: String(p.annee_debut) } : {}),
        },
    ];
});

const annee = (p) => (p.annee_debut ? `${p.annee_debut}${p.annee_fin ? '–' + p.annee_fin : ''}` : '—');

const fmtHistoriqueDate = (d) => {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    return `${dt.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })} à ${dt.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
};

/* Mini-carte OpenStreetmap du périmètre : une pastille par commune géocodée,
   centrée sur le périmètre (une seule commune : centrée dessus). Les communes
   marquées « anomalie » gardent leur contour rouge, comme sur la carte
   principale. */
const mapRef = ref(null);
const cvdMode = ref(false);

const geoPoints = computed(() => props.projet.communes
    .filter((c) => c.lat != null && c.lon != null && (Number(c.lat) !== 0 || Number(c.lon) !== 0))
    .map((c) => ({ ...c, lat: Number(c.lat), lon: Number(c.lon) })));

let map = null;
let L = null;
let markers = [];

function addMarkers() {
    const st = props.projet.statut;
    for (const c of geoPoints.value) {
        const m = L.circleMarker([c.lat, c.lon], {
            radius: 6,
            color: c.anomalie ? '#b91c1c' : '#fff',
            weight: c.anomalie ? 2 : 1,
            dashArray: st === 'a_venir' && !c.anomalie ? '1.5,3' : undefined,
            fillColor: COLOR[st] || COLOR.inconnu,
            fillOpacity: 0.9,
        }).addTo(map);
        m.bindTooltip(`<b>${c.libelle}</b>`, { direction: 'top', offset: [0, -8], className: 'abc-tooltip' });
        markers.push(m);
    }
}

/** Mode daltonien : même palette Okabe-Ito et même préférence que la carte principale. */
function onCvdToggle() {
    cvdMode.value = !cvdMode.value;
    try {
        window.localStorage.setItem('abc.cvd', cvdMode.value ? '1' : '0');
    } catch (e) {
        // stockage indisponible (navigation privée) : la bascule reste valable pour la session
    }
    applyPalette(cvdMode.value ? 'cvd' : DEFAULT_PALETTE_KEY);
    const st = props.projet.statut;
    for (const m of markers) m.setStyle({ fillColor: COLOR[st] || COLOR.inconnu });
}

onMounted(async () => {
    if (geoPoints.value.length === 0) return;

    let cvdStored = null;
    try {
        cvdStored = window.localStorage.getItem('abc.cvd');
    } catch (e) {
        cvdStored = null;
    }
    if (cvdStored === '1') {
        cvdMode.value = true;
        applyPalette('cvd');
    }

    const [{ default: leaf }] = await Promise.all([import('leaflet')]);
    L = leaf;

    map = L.map(mapRef.value, { scrollWheelZoom: false, zoomSnap: 0.5 }).setView([46.5, 2.5], 6);
    L.control.scale({ imperial: false, maxWidth: 160 }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);

    addMarkers();

    const pts = geoPoints.value.map((c) => [c.lat, c.lon]);
    if (pts.length === 1) map.setView(pts[0], 12);
    else map.fitBounds(pts, { padding: [30, 30] });
});

onBeforeUnmount(() => {
    if (map) map.remove();
    map = null;
    markers = [];
});
</script>

<template>
    <Layout>
        <Seo
            :title="pageTitle"
            :description="pageDescription"
            :jsonLd="jsonLd"
        />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <div class="fiche-header d-flex flex-wrap align-items-baseline gap-2 mb-3">
                <h1 class="h3 mb-0">{{ projet.nom }}</h1>
                <span class="badge" :class="statutClass(projet.statut)">{{ projet.statut_label }}</span>
                <small v-if="!projet.statut_verifie" class="text-muted">d'après la source originale</small>
                <small v-else class="text-muted">statut vérifié manuellement</small>
                <span class="badge text-bg-light">{{ projet.source_label }}</span>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <table class="table table-sm mb-0 align-middle">
                                <tbody>
                                    <tr v-if="projet.structure_porteuse">
                                        <th scope="row" class="text-muted" style="width:200px">Structure porteuse</th>
                                        <td>{{ projet.structure_porteuse }} <template v-if="projet.type_de_structure_porteuse"> <small class="text-muted">({{ projet.type_de_structure_porteuse }})</small></template></td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-muted">Années</th>
                                        <td>{{ annee(projet) }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-muted">Communes concernées</th>
                                        <td>{{ projet.communes.length }}<span v-if="projet.communes_anomalies"> (+ {{ projet.communes_anomalies }} écartée(s))</span></td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-muted">Source</th>
                                        <td>{{ projet.source_label }}</td>
                                    </tr>
                                    <tr v-if="projet.url_page">
                                        <th scope="row" class="text-muted">Lien source</th>
                                        <td><a :href="projet.url_page" target="_blank" rel="noopener">Page du registre <span aria-hidden="true">↗</span></a></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-if="projet.description" class="mt-3">
                                <h2 class="h6">Description</h2>
                                <p style="white-space: pre-wrap;" class="mb-0">{{ projet.description }}</p>
                            </div>

                            <div v-if="projet.verification" class="mt-3 small">
                                <span :class="projet.verification.etat === 'douteux' || projet.verification.etat === 'introuvable' ? 'text-danger' : 'text-success'" class="fw-semibold">{{ projet.verification.label }}</span>
                                <template v-if="projet.verification.note">&nbsp;— {{ projet.verification.note }}</template>
                                <template v-if="projet.verification.lien">&nbsp;(<a :href="projet.verification.lien" target="_blank" rel="noopener">lien</a>)</template>
                            </div>
                            <div v-if="projet.donnees_2022" class="alert alert-warning small mt-3 mb-0">
                                Statut figé à l'instantané des archives 2022 — site officiel hors ligne, statut possiblement obsolète.
                            </div>
                            <div v-else-if="projet.estime_termine" class="alert alert-info small mt-3 mb-0">
                                Projet débuté en {{ projet.annee_debut }} (il y a plus de 5 ans) : terminé selon l'estimation, statut officiel inconnu.
                            </div>
                            <div v-else-if="projet.potentiellement_termine" class="alert alert-light small mt-3 mb-0">
                                Début {{ projet.annee_debut }}, durée d'un ABC ≈ 3 ans : potentiellement terminé.
                            </div>
                            <div v-else-if="projet.potentiellement_en_cours" class="alert alert-light small mt-3 mb-0">
                                Début annoncé {{ projet.annee_debut }}, toujours « va débuter » d'après la source originale : potentiellement en cours.
                            </div>
                        </div>
                    </div>

                    <h2 class="h5">Communes du périmètre</h2>
                    <p v-if="projet.communes.length === 0" class="text-muted small">Aucune commune répertoriée.</p>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <Link
                            v-for="c in projet.communes"
                            :key="c.code"
                            :href="`/commune/${c.code}`"
                            class="list-group-item border rounded-3 px-3 py-2 small text-decoration-none text-reset"
                        >
                            {{ c.libelle }}
                            <template v-if="c.departement_label"><small class="text-muted"> · {{ c.departement_label }}</small></template>
                        </Link>
                    </div>

                    <section v-if="projet.historique?.length" class="mb-4" aria-label="Historique des modifications">
                        <h2 class="h5"><i class="bi bi-clock-history me-1" aria-hidden="true"></i> Historique des modifications</h2>
                        <div class="card shadow-sm">
                            <ul class="list-group list-group-flush historique-list">
                                <li v-for="(h, i) in projet.historique" :key="`${h.date}-${i}`" class="list-group-item small">
                                    <span class="date">{{ fmtHistoriqueDate(h.date) }}</span>
                                    <span class="label">{{ h.label }}</span>
                                    <span v-if="h.detail" class="detail">({{ h.detail }})</span>
                                    <span v-if="h.auteur" class="detail">· par {{ h.auteur }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>

                <div class="col-lg-5">
                    <div class="card shadow-sm projet-map-card">
                        <template v-if="geoPoints.length">
                            <div class="projet-map-wrap position-relative">
                                <div ref="mapRef" class="projet-map"></div>
                                <div class="map-north" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 3 5 22 12 16 19 22 12 3" fill="#dc2626"/></svg>
                                    <span style="font-size:15px">N</span>
                                </div>
                                <button
                                    type="button"
                                    class="map-cvd-btn"
                                    :class="{ active: cvdMode }"
                                    :aria-pressed="cvdMode ? 'true' : 'false'"
                                    title="Couleurs renforcées pour les personnes daltoniennes (palette Okabe-Ito). Préférence mémorisée sur cet appareil."
                                    @click="onCvdToggle"
                                >
                                    <i class="bi bi-universal-access" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="card-body pt-3 pb-3">
                                <div class="legend-title">Légende</div>
                                <div class="d-flex flex-wrap gap-3 small">
                                    <span class="legend-item"><span class="chip" style="background:var(--ok, #2d6a4f)"></span>En cours</span>
                                    <span class="legend-item"><span class="chip chip-dash"></span>Va débuter</span>
                                    <span class="legend-item"><span class="chip" style="background:var(--fini, #8d6e63)"></span>Terminé</span>
                                    <span class="legend-item"><span class="chip" style="background:var(--inconnu, #a8a29e)"></span>Statut inconnu</span>
                                    <span v-if="projet.communes_anomalies" class="legend-item"><span class="chip chip-ano"></span>Commune écartée (incohérente)</span>
                                </div>
                                <p class="small text-muted mb-0 mt-2">
                                    Pastille : commune du périmètre ({{ geoPoints.length }} géolocalisée(s) sur {{ projet.communes.length }}). Fond : OpenStreetMap.
                                </p>
                            </div>
                        </template>
                        <div v-else class="card-body text-muted small">
                            Aucune commune de ce projet n'a pu être géolocalisée : la carte n'est pas disponible.
                        </div>
                    </div>
                </div>
            </div>

            <p class="mt-4">
                <Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link>
            </p>
        </div>
    </Layout>
</template>

<style scoped>
/* Badges de l'en-tête : alignés sur la ligne de base du titre, puis remontés
   de 0,25em pour compenser le padding sous la ligne de base de la pastille —
   sans ce décalage, la masse optique du badge paraît plus basse que le titre. */
.fiche-header .badge { position: relative; top: -0.25em; }
.historique-list .date { color: #94a3b8; font-variant-numeric: tabular-nums; margin-right: .5rem; }
.historique-list .label { color: #334155; }
.historique-list .detail { color: #64748b; }

/* Mini-carte du périmètre : la colonne reste visible pendant le défilement
   de la colonne d'information (grandes fiches, historique long). */
@media (min-width: 992px) {
    .projet-map-card { position: sticky; top: 1rem; }
}
.projet-map { height: 420px; border-radius: calc(0.375rem - 1px) calc(0.375rem - 1px) 0 0; }
.map-north {
    position: absolute; z-index: 1000; top: 84px; left: 12px;
    display: flex; align-items: center; gap: 4px;
    background: rgba(255,255,255,.95); padding: 5px 9px; border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    border: 1px solid rgba(255,255,255,.8);
    color: #1f2937; font-size: 14px; font-weight: 700; line-height: 1;
}
.map-cvd-btn {
    position: absolute; z-index: 1000; top: 10px; right: 10px;
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: none; border-radius: 8px;
    background: rgba(255,255,255,.92); color: #374151;
    box-shadow: 0 2px 6px rgba(0,0,0,.2);
    cursor: pointer;
}
.map-cvd-btn:hover { background: #fff; color: #111827; box-shadow: 0 3px 10px rgba(0,0,0,.25); }
.map-cvd-btn.active { background: #2d6a4f; color: #fff; }
.map-cvd-btn.active:hover { background: #2d6a4f; color: #fff; }
.legend-title {
    font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #94a3b8; margin-bottom: 5px;
}
.legend-item { display: inline-flex; align-items: center; gap: 6px; color: #374151; line-height: 1.5; }
.chip { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
.chip-dash { background: transparent; border: 1.5px dashed var(--debut, #689f38); box-sizing: border-box; }
.chip-ano { background: transparent; border: 2px solid #b91c1c; box-sizing: border-box; }

/* Info-bulle Leaflet (éléments créés hors du template : :deep requis). */
:deep(.abc-tooltip) { background: #1f2937; color: #fff; border: none; border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 500; box-shadow: 0 2px 6px rgba(0,0,0,.3); }
:deep(.abc-tooltip)::before { border-top-color: #1f2937; }
</style>
