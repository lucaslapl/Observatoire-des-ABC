<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
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
});

const ETAT_LABEL = { en_attente: 'en attente', validee: 'validée', refusee: 'refusée', retiree: 'retirée' };
const TYPE_LABEL = { statut: 'Statut', date_debut: 'Date de début', note: 'Note', lien: 'Lien', autre: 'Autre info' };
const STATUT_LABEL = { en_cours: 'En cours', a_venir: 'Va débuter', termine: 'Terminé', inconnu: 'Statut inconnu' };
const SOURCE_LABEL = {
    'data.gouv': 'Registre OFB',
    wayback: 'Archives 2022',
    'fondsvert-p113-2024': 'Fonds vert 2024',
    'fondsvert-p113-2025': 'Fonds vert 2025',
};
const VERDICT_LABEL = {
    confirme_termine: '✓ Vérifié : Terminé',
    confirme_en_cours: '✓ Vérifié : En cours',
    toujours_a_venir: '✓ Vérifié : Va débuter',
    confirme_date: '✓ Vérifié : Date confirmée',
    introuvable: '⚠ Vérifié : introuvable',
    douteux: '⚠ Vérifié : incertain',
};

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

const formatDateFr = (d) => (d ? new Date(d).toLocaleDateString('fr-FR') : '');

function openActualite(a) {
    actualiteForm.id = a ? a.id : null;
    actualiteForm.titre = a ? a.titre : '';
    actualiteForm.contenu = a ? a.contenu : '';
    actualiteForm.statut = a ? a.statut : 'publie';
    actualiteForm.date_publication = a && a.date_publication ? a.date_publication.slice(0, 10) : '';
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

const tabs = [
    { key: 'en_attente', label: 'À modérer' },
    { key: 'validee', label: 'Appliquées' },
    { key: 'historique', label: 'Refusées / retirées' },
    { key: 'toutes', label: 'Toutes' },
];

const etatLabel = (s) => ETAT_LABEL[s] || s;
const typeLabel = (t) => TYPE_LABEL[t] || t;
const statutLabel = (s) => STATUT_LABEL[s] || s;
const sourceLabel = (s) => SOURCE_LABEL[s] || s;
const verdictLabel = (v) => VERDICT_LABEL[v] || v;

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

function parsePayload(p) {
    if (p && typeof p === 'object') return p;
    try {
        return JSON.parse(p || '{}');
    } catch (e) {
        return {};
    }
}

function payloadLines(payload) {
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
    if (activeTab.value === 'historique') return all.filter((c) => c.statut === 'refusee' || c.statut === 'retiree');
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
        const time = j.data.path.replace(/^.*abc-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\..*$/, '$3/$2/$1 $4:$5:$6');
        maintenanceMsg.value = `Sauvegarde créée : ${time} (${j.data.kept} sauvegardes conservées).`;
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
            <h1 class="h4 mb-4">Administration</h1>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Maintenance</div>
                <div class="card-body py-2">
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <button class="btn btn-primary btn-sm" :disabled="busy" @click="collect">🔄 Collecter les données</button>
                        <button class="btn btn-secondary btn-sm" :disabled="busy" @click="backup">💾 Sauvegarder la base</button>
                        <span v-if="busy" class="spinner-border spinner-border-sm text-success" role="status"></span>
                    </div>
                    <div v-if="maintenanceMsg" class="small mt-1" :class="maintenanceOk ? 'text-success' : 'text-danger'">{{ maintenanceMsg }}</div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold py-2">Actualités</div>
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button v-if="!actualiteForm.id" class="btn btn-sm btn-outline-primary" @click="openActualite(null)">➕ Nouvelle actualité</button>
                        <button v-else class="btn btn-sm btn-outline-secondary" @click="openActualite(null)">↩ Annuler l'édition</button>
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
                        <textarea v-model="actualiteForm.contenu" rows="3" class="form-control form-control-sm mt-2" placeholder="Contenu de l'actualité…"></textarea>
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
                                    {{ formatDateFr(a.date_publication) }} · {{ (a.contenu || '').slice(0, 80) }}{{ (a.contenu || '').length > 80 ? '…' : '' }}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="openActualite(a)">✎ Modifier</button>
                            <button class="btn btn-sm btn-outline-danger" :disabled="busy" @click="destroyActualite(a)">🗑 Supprimer</button>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                    <span class="fw-semibold">Exclusions (projets supprimés)</span>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" :disabled="exclusionsBusy" @click="exportAudit">⬇ Exporter le journal d'audit</button>
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
                            <button class="btn btn-sm btn-outline-secondary" :disabled="exclusionsBusy" @click="unexcludeProjet(e)">↩ Ré-import</button>
                        </li>
                    </ul>
                    <div class="small text-muted mt-2">La levée d'exclusion ne recrée pas le projet : il faut ensuite lancer un collect.</div>
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
                        <div v-if="c.commentaire" class="small text-muted mt-1">💬 {{ c.commentaire }}</div>
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
                            <button class="btn btn-sm btn-success" :disabled="busy" @click="act(c.id, 'valider')">✓ Valider</button>
                            <button class="btn btn-sm btn-danger" :disabled="busy" @click="act(c.id, 'refuser')">✗ Refuser</button>
                        </div>
                        <div v-else-if="c.statut === 'validee'" class="d-flex gap-2 flex-wrap mt-2">
                            <button class="btn btn-sm btn-warning" :disabled="busy" @click="act(c.id, 'retirer')">↩ Retirer (rollback)</button>
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
</style>
