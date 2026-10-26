<script setup>
import { ref, computed, reactive, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import { STATUT_LABEL, SRC_LABEL, VERDICT } from '../map/constants.js';
import { parsePayload, payloadLines } from '../utils/contributionDisplay.js';

const props = defineProps({
    // false = simple modérateur : seules les sections de modération
    // des contributions sont affichées.
    isAdmin: {
        type: Boolean,
        default: false,
    },
    meta: {
        type: Object,
        default: null,
    },
    contributions: {
        type: Array,
        default: () => [],
    },
    actualites: {
        type: Array,
        default: () => [],
    },
    exclusions: {
        type: Array,
        default: () => [],
    },
    comptes: {
        type: Array,
        default: () => [],
    },
    journalVerifications: {
        type: Array,
        default: () => [],
    },
    journalFusions: {
        type: Array,
        default: () => [],
    },
});

const ETAT_LABEL = { en_attente: 'en attente', validee: 'validée', refusee: 'refusée', annulee: 'annulée', retiree: 'retirée' };
const TYPE_LABEL = { statut: 'Statut', date_debut: 'Date de début', note: 'Note', lien: 'Lien', autre: 'Autre info' };

const activeTab = ref('en_attente');
const busy = ref(false);
const actionMsg = ref('');
const actionOk = ref(true);
const maintenanceMsg = ref('');
const maintenanceOk = ref(true);

const actualiteForm = reactive({
    id: null,
    titre: '',
    contenu: '',
    statut: 'publie',
    date_publication: '',
});
const actualiteMsg = ref('');
const actualiteOk = ref(true);

// --- Éditeur Markdown ---
// Barre d'outils insérant la syntaxe autour de la sélection, et aperçu
// rendu par le serveur (POST /actualites/preview) : même rendu que la
// page publique, sans bibliothèque Markdown côté client.
const contenuTextarea = ref(null);
const showPreview = ref(false);
const previewHtml = ref('');
const previewBusy = ref(false);
let previewTimer = null;
let previewSeq = 0;

function focusContenu() {
    nextTick(() => contenuTextarea.value?.focus());
}

/** Entoure la sélection de balises Markdown (`**gras**`, `[lien](url)`…). */
function wrapSelection(before, after = before, placeholder = 'texte') {
    const el = contenuTextarea.value;
    const value = actualiteForm.contenu;
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? start;
    const selected = value.slice(start, end) || placeholder;
    actualiteForm.contenu = value.slice(0, start) + before + selected + after + value.slice(end);
    const caret = start + before.length;
    nextTick(() => {
        if (!el) return;
        el.focus();
        el.selectionStart = caret;
        el.selectionEnd = caret + selected.length;
    });
}

/** Préfixe chaque ligne de la sélection (`## Titre`, `- item`, `> citation`…). */
function prefixLines(prefix) {
    const el = contenuTextarea.value;
    const value = actualiteForm.contenu;
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? start;
    const lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
    const lineEnd = end + (value.slice(end).indexOf('\n') === -1 ? 0 : value.slice(end).indexOf('\n'));
    const block = value.slice(lineStart, lineEnd);
    const prefixed = block
        .split('\n')
        .map((l) => (l.startsWith(prefix) ? l : prefix + l))
        .join('\n');
    actualiteForm.contenu = value.slice(0, lineStart) + prefixed + value.slice(lineEnd);
    focusContenu();
}

async function refreshPreview() {
    const contenu = actualiteForm.contenu;
    const seq = ++previewSeq;
    previewBusy.value = true;
    try {
        const r = await axios.post('/actualites/preview', { contenu });
        if (seq === previewSeq) previewHtml.value = r.data.html;
    } catch {
        // Silencieux : l'aperçu reprendra au prochain arrêt de frappe.
    } finally {
        if (seq === previewSeq) previewBusy.value = false;
    }
}

watch(
    () => [showPreview.value, actualiteForm.contenu],
    () => {
        if (!showPreview.value) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 300);
    },
);

const formatDateFr = (d) => (d ? new Date(d).toLocaleDateString('fr-FR') : '');
const formatDateTimeFr = (d) => (d ? new Date(d).toLocaleString('fr-FR') : '');

function openActualite(a) {
    actualiteForm.id = a ? a.id : null;
    actualiteForm.titre = a ? a.titre : '';
    actualiteForm.contenu = a ? a.contenu : '';
    actualiteForm.statut = a ? a.statut : 'publie';
    actualiteForm.date_publication = a && a.date_publication ? a.date_publication.slice(0, 10) : '';
    showPreview.value = false;
    previewHtml.value = '';
}

async function saveActualite() {
    if (!actualiteForm.titre.trim()) {
        actualiteMsg.value = 'Le titre est requis.';
        actualiteOk.value = false;
        return;
    }
    if (!actualiteForm.contenu.trim()) {
        actualiteMsg.value = 'Le contenu est requis.';
        actualiteOk.value = false;
        return;
    }
    busy.value = true;
    actualiteMsg.value = '';
    const payload = {
        titre: actualiteForm.titre,
        contenu: actualiteForm.contenu,
        statut: actualiteForm.statut,
        date_publication: actualiteForm.date_publication || null,
    };
    try {
        if (actualiteForm.id) {
            await router.put(`/actualites/${actualiteForm.id}`, payload);
        } else {
            await router.post('/actualites', payload);
        }
        actualiteMsg.value = 'Actualité enregistrée.';
        actualiteOk.value = true;
        openActualite(null);
    } catch (e) {
        actualiteMsg.value = 'Erreur : ' + errMsg(e);
        actualiteOk.value = false;
    } finally {
        busy.value = false;
    }
}

async function destroyActualite(a) {
    if (!window.confirm(`Supprimer l'actualité « ${a.titre} » ?`)) return;
    busy.value = true;
    actualiteMsg.value = '';
    try {
        await router.delete(`/actualites/${a.id}`);
        actualiteMsg.value = 'Actualité supprimée.';
        actualiteOk.value = true;
    } catch (e) {
        actualiteMsg.value = 'Erreur : ' + errMsg(e);
        actualiteOk.value = false;
    } finally {
        busy.value = false;
    }
}

const exclusionsMsg = ref('');
const exclusionsOk = ref(true);
const exclusionsBusy = ref(false);

async function unexcludeProjet(e) {
    if (!window.confirm(`Le projet « ${e.nom || e.projet_id} » (${e.projet_id}) sera ré-importé au prochain collect. Continuer ?`)) return;
    exclusionsBusy.value = true;
    exclusionsMsg.value = '';
    try {
        await axios.delete(`/api/admin/exclusions/${encodeURIComponent(e.projet_id)}`);
        exclusionsMsg.value = 'Exclusion levée.';
        exclusionsOk.value = true;
        router.reload({ only: ['exclusions'] });
    } catch (error) {
        exclusionsMsg.value = 'Erreur : ' + errMsg(error);
        exclusionsOk.value = false;
    } finally {
        exclusionsBusy.value = false;
    }
}

async function exportAudit() {
    exclusionsBusy.value = true;
    exclusionsMsg.value = '';
    try {
        const r = await axios.get('/api/admin/exclusions/export', { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([r.data]));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'audit-abc.csv';
        link.click();
        window.URL.revokeObjectURL(url);
        exclusionsMsg.value = 'Journal exporté.';
        exclusionsOk.value = true;
    } catch (error) {
        exclusionsMsg.value = 'Erreur : ' + errMsg(error);
        exclusionsOk.value = false;
    } finally {
        exclusionsBusy.value = false;
    }
}

// Rôle modérateur (carte « Comptes ») : promotion / retrait.
const comptesMsg = ref('');
const comptesOk = ref(true);
const comptesBusy = ref(false);

async function setModerateur(u, add) {
    if (!add && !window.confirm(`Retirer le rôle modérateur à ${u.name} ? Il redevient simple contributeur.`)) return;
    comptesBusy.value = true;
    comptesMsg.value = '';
    try {
        const url = `/api/admin/comptes/${u.id}/moderateur`;
        if (add) {
            await axios.post(url);
        } else {
            await axios.delete(url);
        }
        comptesMsg.value = add
            ? `${u.name} est maintenant modérateur.`
            : `Rôle modérateur retiré à ${u.name}.`;
        comptesOk.value = true;
        router.reload({ only: ['comptes'] });
    } catch (e) {
        comptesMsg.value = 'Erreur : ' + errMsg(e);
        comptesOk.value = false;
    } finally {
        comptesBusy.value = false;
    }
}

const tabs = [
    { key: 'en_attente', label: 'À modérer' },
    { key: 'validee', label: 'Appliquées' },
    { key: 'historique', label: 'Annulées / refusées / retirées' },
    { key: 'toutes', label: 'Toutes' },
];

const etatLabel = (s) => ETAT_LABEL[s] || s;
const typeLabel = (t) => TYPE_LABEL[t] || t;
const statutLabel = (s) => STATUT_LABEL[s] || s;
const sourceLabel = (s) => SRC_LABEL[s] || s;
const verdictLabel = (v) => (VERDICT[v] || {}).label || v;

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

const cards = computed(() =>
    (props.contributions || []).map((c) => ({
        ...c,
        payload: payloadLines(parsePayload(c.payload_json)),
    }))
);

const visibleCards = computed(() => {
    const all = cards.value;
    if (activeTab.value === 'en_attente') return all.filter((c) => c.statut === 'en_attente');
    if (activeTab.value === 'validee') return all.filter((c) => c.statut === 'validee');
    if (activeTab.value === 'historique') return all.filter((c) => ['refusee', 'annulee', 'retiree'].includes(c.statut));
    return all;
});

const statCards = computed(() => {
    const m = props.meta || {};
    return [
        { label: 'Projets', value: m.countProjets ?? 0 },
        { label: 'Potentiellement terminés', value: m.countPotentiellementTermines ?? 0 },
        { label: 'Potentiellement en cours', value: m.countPotentiellementEnCours ?? 0 },
        { label: 'Archives 2022', value: m.countDonnees2022 ?? 0 },
        { label: 'Estimés terminés', value: m.countEstimes ?? 0 },
        { label: 'Vérifiés', value: m.countVerifies ?? 0 },
        { label: 'Anomalies', value: m.countAnomalies ?? 0 },
        { label: 'Contributions à modérer', value: m.countContributionsEnAttente ?? 0 },
    ];
});

async function act(id, action) {
    let body = {};
    if (action === 'refuser') {
        const note = window.prompt('Note de refus (optionnelle) :');
        if (note === null) return;
        body = { note_admin: note };
    }
    busy.value = true;
    actionMsg.value = '';
    try {
        await axios.post(`/api/admin/contributions/${id}/${action}`, body);
        actionMsg.value = 'Action effectuée.';
        actionOk.value = true;
        router.reload({ only: ['contributions'] });
    } catch (e) {
        actionMsg.value = 'Erreur : ' + errMsg(e);
        actionOk.value = false;
    } finally {
        busy.value = false;
    }
}

async function backup() {
    busy.value = true;
    maintenanceMsg.value = '';
    try {
        const j = await axios.post('/api/admin/backup');
        const time = j.data.path.replace(/^.*abc-(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})\..*$/, '$3/$2/$1 $4:$5:$6');
        const fmt = j.data.format === 'sql' ? ' (SQL data-only — proc_open désactivé sur cet hébergeur)' : '';
        maintenanceMsg.value = `Sauvegarde créée : ${time} (${j.data.kept} sauvegardes conservées)${fmt}.`;
        maintenanceOk.value = true;
    } catch (e) {
        maintenanceMsg.value = 'Erreur : ' + errMsg(e);
        maintenanceOk.value = false;
    } finally {
        busy.value = false;
    }
}

async function collect() {
    if (!window.confirm('Lancer la collecte des données ? Cela va effacer et reconstruire toute la base.')) return;
    busy.value = true;
    maintenanceMsg.value = '';
    try {
        const j = await axios.post('/api/admin/collect');
        const s = j.data.summary || {};
        const statusObj = s.statuses || {};
        const statusLines = Object.entries(statusObj).map(([statut, n]) => `${statutLabel(statut)}: ${n}`).join(', ');
        maintenanceMsg.value = `Collecte terminée : ${s.total ?? 0} projets, ${s.communes ?? 0} communes (${s.geocoded ?? 0} géocodées). ${statusLines}`;
        maintenanceOk.value = true;
        router.reload({ only: ['contributions', 'meta'] });
    } catch (e) {
        maintenanceMsg.value = 'Erreur : ' + errMsg(e);
        maintenanceOk.value = false;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Layout>
        <Seo noindex title="Administration" description="Panneau d'administration de l'Observatoire des ABC." />
        <div class="container py-4">
            <h1 class="h4 mb-4">{{ isAdmin ? 'Administration' : 'Modération' }}</h1>

            <div v-if="isAdmin" class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Maintenance</div>
                <div class="card-body py-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <button class="btn btn-primary btn-sm" :disabled="busy" @click="collect"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Collecter les données</button>
                        <button class="btn btn-secondary btn-sm" :disabled="busy" @click="backup"><i class="bi bi-save me-1" aria-hidden="true"></i>Sauvegarder la base</button>
                        <span v-if="busy" class="spinner-border spinner-border-sm text-success" role="status"></span>
                    </div>
                    <div v-if="maintenanceMsg" class="small mt-1" :class="maintenanceOk ? 'text-success' : 'text-danger'">{{ maintenanceMsg }}</div>
                </div>
            </div>

            <div v-if="isAdmin" class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Actualités</div>
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button v-if="!actualiteForm.id" class="btn btn-sm btn-outline-primary" @click="openActualite(null)"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nouvelle actualité</button>
                        <button v-else class="btn btn-sm btn-outline-secondary" @click="openActualite(null)"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Annuler l'édition</button>
                    </div>
                    <form @submit.prevent="saveActualite" class="border rounded-3 p-2 mb-2 bg-light">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <input v-model="actualiteForm.titre" type="text" class="form-control form-control-sm" style="max-width:280px"
                                :placeholder="actualiteForm.id ? 'Titre (en cours d\'édition)' : 'Titre'" />
                            <select v-model="actualiteForm.statut" class="form-select form-select-sm" style="max-width:130px">
                                <option value="publie">Publié</option>
                                <option value="masque">Masqué</option>
                            </select>
                            <input v-model="actualiteForm.date_publication" type="date" class="form-control form-control-sm" style="max-width:160px" />
                            <button class="btn btn-xs btn-success btn-sm" type="submit" :disabled="busy">
                                {{ actualiteForm.id ? 'Mettre à jour' : 'Publier' }}
                            </button>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-2 mb-1" role="toolbar" aria-label="Mise en forme du contenu (Markdown)">
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Titre de section" @click="prefixLines('## ')">Titre</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Sous-titre" @click="prefixLines('### ')">Sous-titre</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Gras" @click="wrapSelection('**')"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Italique" @click="wrapSelection('*')"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Liste à puces" @click="prefixLines('- ')"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Citation" @click="prefixLines('> ')"><i class="bi bi-quote" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Lien" @click="wrapSelection('[', '](https://)', 'libellé du lien')"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Image" @click="wrapSelection('![', '](https://)', 'texte alternatif')"><i class="bi bi-image" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-sm" :class="showPreview ? 'btn-success' : 'btn-outline-success'" @click="showPreview = !showPreview">
                                <i class="bi bi-eye me-1" aria-hidden="true"></i>Aperçu
                            </button>
                        </div>
                        <textarea v-show="!showPreview" ref="contenuTextarea" v-model="actualiteForm.contenu" rows="10" class="form-control form-control-sm mt-2" placeholder="Contenu de l'actualité (Markdown)…"></textarea>
                        <div v-if="showPreview" class="border rounded-3 p-3 mt-2 bg-white actualite-contenu">
                            <div v-if="previewBusy" class="text-muted small mb-2">Rendu en cours…</div>
                            <div v-if="!previewHtml && !previewBusy" class="text-muted">Aperçu vide.</div>
                            <div v-html="previewHtml"></div>
                        </div>
                        <div class="form-text">Markdown : **gras**, *italique*, ## Titre, listes « - », [libellé](url), ![alt](url). Le HTML brut est affiché comme du texte.</div>
                    </form>
                    <div v-if="actualiteMsg" class="small mb-2" :class="actualiteOk ? 'text-success' : 'text-danger'">{{ actualiteMsg }}</div>

                    <div v-if="actualites.length === 0" class="text-muted small py-2">Aucune actualité.</div>
                    <ul class="list-group list-group-flush">
                        <li v-for="a in actualites" :key="a.id"
                            class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                            <div class="flex-grow-1" style="min-width:200px">
                                <div class="fw-semibold small">
                                    <span class="chip" :class="a.statut">{{ a.statut === 'publie' ? 'publié' : 'masqué' }}</span>
                                    &nbsp;{{ a.titre }}
                                </div>
                                <div class="small text-muted">
                                    {{ formatDateFr(a.date_publication) }} · {{ a.extrait }}{{ (a.contenu || '').length > 80 ? '…' : '' }}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="openActualite(a)"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Modifier</button>
                            <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="destroyActualite(a)"><i class="bi bi-trash me-1" aria-hidden="true"></i>Supprimer</button>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="isAdmin" class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                    <span class="fw-semibold">Exclusions (projets supprimés)</span>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" :disabled="exclusionsBusy" @click="exportAudit"><i class="bi bi-download me-1" aria-hidden="true"></i>Exporter le journal d'audit</button>
                </div>
                <div class="card-body py-2">
                    <div v-if="exclusionsMsg" class="small mb-2" :class="exclusionsOk ? 'text-success' : 'text-danger'">{{ exclusionsMsg }}</div>
                    <div v-if="exclusions.length === 0" class="text-muted small py-2">
                        Aucune exclusion. Les projets supprimés depuis la carte (admin) apparaissent ici et ne sont pas ré-importés au prochain collect.
                    </div>
                    <ul v-else class="list-group list-group-flush">
                        <li v-for="e in exclusions" :key="e.projet_id"
                            class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                            <div class="flex-grow-1" style="min-width:220px">
                                <div class="fw-semibold small">{{ e.nom || e.projet_id }}</div>
                                <div class="small text-muted">
                                    {{ e.projet_id }}{{ e.motif ? ` · motif : ${e.motif}` : '' }}{{ e.par_admin ? ` · par ${e.par_admin}` : '' }}{{ e.cree_le ? ` · le ${formatDateFr(e.cree_le)}` : '' }}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" :disabled="exclusionsBusy" @click="unexcludeProjet(e)"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Ré-import</button>
                        </li>
                    </ul>
                    <div class="small text-muted mt-2">La levée d'exclusion ne recrée pas le projet : il faut ensuite lancer un collect.</div>
                </div>
            </div>

            <div v-if="isAdmin" class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Comptes ({{ comptes.length }})</div>
                <div class="card-body py-2">
                    <div v-if="comptesMsg" class="small mb-2" :class="comptesOk ? 'text-success' : 'text-danger'">{{ comptesMsg }}</div>
                    <div v-if="comptes.length === 0" class="text-muted small py-2">Aucun compte.</div>
                    <div v-else class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0 small">
                            <thead>
                                <tr>
                                    <th scope="col">Nom</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Rôles</th>
                                    <th scope="col">Email vérifié</th>
                                    <th scope="col">Contributions</th>
                                    <th scope="col">Créé le</th>
                                    <th scope="col">Modération</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in comptes" :key="u.id">
                                    <td class="fw-semibold">{{ u.name }}</td>
                                    <td>{{ u.email }}</td>
                                    <td>
                                        <span v-for="r in u.roles" :key="r" class="chip me-1" :class="r">{{ r }}</span>
                                    </td>
                                    <td>
                                        <span v-if="u.email_verifie" class="text-success">oui</span>
                                        <span v-else class="text-warning">non</span>
                                    </td>
                                    <td>{{ u.nb_contributions }}</td>
                                    <td class="text-muted">{{ formatDateFr(u.cree_le) }}</td>
                                    <td>
                                        <span v-if="u.roles.includes('admin')" class="text-muted" title="Le rôle administrateur n'est pas gérable ici">—</span>
                                        <button v-else-if="u.roles.includes('moderateur')" class="btn btn-sm btn-outline-warning"
                                            :disabled="comptesBusy" title="Redevient simple contributeur"
                                            @click="setModerateur(u, false)"><i class="bi bi-dash-lg me-1" aria-hidden="true"></i>Retirer</button>
                                        <button v-else class="btn btn-sm btn-outline-primary"
                                            :disabled="comptesBusy" title="Expert de confiance : peut valider les suggestions et enregistrer des verdicts"
                                            @click="setModerateur(u, true)"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Modérateur</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-muted mt-2">
                        Le modérateur valide les suggestions des contributeurs et enregistre des verdicts directs,
                        sans accès à la maintenance du site.
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div v-for="card in statCards" :key="card.label" class="col-6 col-md-3 col-lg-2">
                    <div class="card shadow-sm h-100">
                        <div class="card-body text-center py-3">
                            <div class="fs-4 fw-bold">{{ card.value }}</div>
                            <div class="small text-muted">{{ card.label }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Données</div>
                <div class="card-body py-2 small">
                    <div v-for="(date, name) in (meta?.sources || {})" :key="name" class="d-flex justify-content-between">
                        <span>{{ sourceLabel(name) }}</span>
                        <span class="text-muted">{{ date }}</span>
                    </div>
                    <div v-if="(meta?.stats || []).length" class="mt-2 d-flex gap-1 flex-wrap">
                        <span v-for="s in meta.stats" :key="s.statut" class="chip">{{ statutLabel(s.statut) }} : {{ s.n }}</span>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Vérifications manuelles ({{ journalVerifications.length }})</div>
                <div class="card-body py-2">
                    <div v-if="journalVerifications.length === 0" class="text-muted small py-2">
                        Aucune vérification manuelle enregistrée pour l'instant. Les verdicts posés depuis la page
                        de vérification (/verify) apparaissent ici, avec leur auteur.
                    </div>
                    <ul v-else class="list-group list-group-flush">
                        <li v-for="(v, i) in journalVerifications" :key="i" class="list-group-item px-0 py-2">
                            <div class="d-flex flex-wrap align-items-baseline gap-2">
                                <span class="fw-semibold small">{{ v.nom || v.projet_id }}</span>
                                <span class="small text-muted">{{ v.projet_id }}</span>
                                <span class="ms-auto small text-muted">{{ formatDateTimeFr(v.date) }}</span>
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-person-check me-1" aria-hidden="true"></i>{{ v.par || 'compte supprimé' }} ·
                                {{ verdictLabel(v.etat) }}<template v-if="v.etat_avant && v.etat_avant !== v.etat">
                                    (remplace « {{ verdictLabel(v.etat_avant) }} »)</template>
                                <template v-if="v.note"> — {{ v.note }}</template>
                                <template v-if="v.lien"> · <a :href="v.lien" target="_blank" rel="noopener">Lien source</a></template>
                            </div>
                        </li>
                    </ul>
                    <div class="small text-muted mt-2">Les 200 dernières vérifications manuelles, toutes les plus récentes d'abord.</div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Fusions de doublons ({{ journalFusions.length }})</div>
                <div class="card-body py-2">
                    <div v-if="journalFusions.length === 0" class="text-muted small py-2">
                        Aucune fusion enregistrée pour l'instant. Les doublons fusionnés depuis la page de
                        vérification (/verify) ou la commande abc:merge-projects apparaissent ici, avec leur
                        auteur et le projet canonique retenu.
                    </div>
                    <ul v-else class="list-group list-group-flush">
                        <li v-for="(f, i) in journalFusions" :key="i" class="list-group-item px-0 py-2">
                            <div class="d-flex flex-wrap align-items-baseline gap-2">
                                <span class="fw-semibold small">{{ f.doublon || f.doublon_id }}</span>
                                <span class="small text-muted">→ fusionné dans</span>
                                <span class="fw-semibold small">{{ f.canonique || f.canonique_id }}</span>
                                <span class="ms-auto small text-muted">{{ formatDateTimeFr(f.date) }}</span>
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-person-check me-1" aria-hidden="true"></i>{{ f.par || 'import / commande' }}
                                <template v-if="f.motif"> — motif : {{ f.motif }}</template>
                            </div>
                        </li>
                    </ul>
                    <div class="small text-muted mt-2">
                        Le doublon devient un alias : son slug redirige en 301 vers le canonique et il n'est
                        pas réimporté au collect. Un snapshot de rollback est écrit à chaque fusion.
                    </div>
                </div>
            </div>

            <div class="bg-white border rounded-top">
                <ul class="nav nav-tabs px-3 pt-2">
                    <li v-for="t in tabs" :key="t.key" class="nav-item">
                        <button class="nav-link" :class="{ active: activeTab === t.key }" @click="activeTab = t.key">{{ t.label }}</button>
                    </li>
                </ul>
            </div>
            <div v-if="actionMsg" class="small mt-2" :class="actionOk ? 'text-success' : 'text-danger'">{{ actionMsg }}</div>

            <div class="mt-3">
                <div v-if="visibleCards.length === 0" class="text-center text-muted py-5">Aucune contribution.</div>
                <div v-for="c in visibleCards" :key="c.id" class="card shadow-sm mb-2">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                        <span class="chip" :class="c.statut">{{ etatLabel(c.statut) }}</span>
                        <span class="fw-semibold">{{ c.projet_nom || c.projet_id }}</span>
                        <span class="text-muted small">· {{ typeLabel(c.type) }}</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="small text-muted">
                            <span v-if="c.structure_porteuse">{{ c.structure_porteuse }} · </span>
                            IP {{ c.ip }} · {{ c.created_at }}<template v-if="c.user_agent"> · {{ c.user_agent.slice(0, 60) }}</template>
                        </div>
                        <div v-if="c.commentaire" class="small text-muted mt-1"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>{{ c.commentaire }}</div>
                        <div class="payload">
                            <template v-for="(line, i) in c.payload" :key="i">
                                <b>{{ line.label }} :</b>
                                <a v-if="line.link" :href="line.value" target="_blank" rel="noopener">{{ line.value }}</a>
                                <span v-else>{{ line.value }}</span>
                                <br v-if="i < c.payload.length - 1" />
                            </template>
                        </div>
                        <div v-if="c.note_admin" class="small text-muted mt-1">Note admin : {{ c.note_admin }}</div>
                        <div v-if="c.traite_par" class="small text-muted">Traité par {{ c.traite_par }} le {{ c.traite_le }}</div>
                        <div v-if="c.verif_etat && c.verif_etat !== 'a_verifier'" class="small text-muted">
                            Verdict actuel : {{ verdictLabel(c.verif_etat) }}
                            <template v-if="c.verif_note"> — {{ c.verif_note }}</template>
                            <template v-if="c.verif_lien"> · <a :href="c.verif_lien" target="_blank" rel="noopener">Lien source</a></template>
                        </div>
                        <div v-if="c.statut === 'en_attente'" class="d-flex gap-2 flex-wrap mt-2">
                            <button class="btn btn-sm btn-success" :disabled="busy" @click="act(c.id, 'valider')"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Valider</button>
                            <button class="btn btn-sm btn-danger" :disabled="busy" title="Information erronée : la suggestion est rejetée"
                                @click="act(c.id, 'refuser')"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Refuser</button>
                            <button class="btn btn-sm btn-outline-secondary" :disabled="busy" title="Erreur d'envoi ou erreur de l'auteur : la suggestion est annulée"
                                @click="act(c.id, 'annuler')"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Annuler</button>
                        </div>
                        <div v-else-if="c.statut === 'validee'" class="d-flex gap-2 flex-wrap mt-2">
                            <button class="btn btn-sm btn-warning" :disabled="busy" @click="act(c.id, 'retirer')"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Retirer (rollback)</button>
                        </div>
                    </div>
                </div>
            </div>

            <p class="foot">
                RGPD : l'adresse IP des contributeurs est conservée uniquement pour la modération des
                corrections et ne sert à aucun autre traitement.
            </p>
        </div>
    </Layout>
</template>

<style scoped>
.chip {
    display: inline-block;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #e2e8f0;
    color: #334155;
}
.chip.en_attente { background: #fef9c3; color: #854d0e; }
.chip.validee { background: #e8f5e9; color: #1b5e20; }
.chip.refusee { background: #fde8e8; color: #7f1d1d; }
.chip.retiree { background: #e2e8f0; color: #475569; }
.payload {
    margin-top: 6px;
    font-size: 13px;
    background: #f8fafc;
    border-radius: 6px;
    padding: 8px 10px;
    white-space: pre-wrap;
    word-break: break-word;
    border: 1px solid #e9ecef;
}
.payload b { color: #0f172a; }
.foot {
    margin-top: 20px;
    font-size: 12px;
    color: #64748b;
    line-height: 1.6;
}
/* Aperçu Markdown : images fluides, citations discrètes (comme en public). */
.actualite-contenu :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.25rem;
}
.actualite-contenu :deep(blockquote) {
    border-left: 3px solid #198754;
    padding-left: 1rem;
    color: #6c757d;
    margin-bottom: 1rem;
}
</style>
