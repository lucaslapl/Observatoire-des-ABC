<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import Pagination from '../../Components/Admin/Pagination.vue';
import { STATUT_LABEL, VERDICT } from '../../map/constants.js';
import { parsePayload, payloadLines } from '../../utils/contributionDisplay.js';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    contributions: { type: Object, required: true },
    statut: { type: String, default: 'en_attente' },
    counts: { type: Object, default: () => ({}) },
});

const ETAT_LABEL = { en_attente: 'en attente', validee: 'validée', refusee: 'refusée', annulee: 'annulée', retiree: 'retirée' };
const TYPE_LABEL = { statut: 'Statut', date_debut: 'Date de début', note: 'Note', lien: 'Lien', autre: 'Autre info' };

const tabs = [
    { key: 'en_attente', label: 'À modérer' },
    { key: 'validee', label: 'Appliquées' },
    { key: 'historique', label: 'Annulées / refusées / retirées' },
    { key: 'toutes', label: 'Toutes' },
];

const busy = ref(false);
const actionMsg = ref('');
const actionOk = ref(true);

const etatLabel = (s) => ETAT_LABEL[s] || s;
const typeLabel = (t) => TYPE_LABEL[t] || t;
const verdictLabel = (v) => (VERDICT[v] || {}).label || v;

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

const cards = computed(() =>
    (props.contributions.data || []).map((c) => ({
        ...c,
        payload: payloadLines(parsePayload(c.payload_json)),
    })),
);

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
        router.reload({ only: ['contributions', 'counts', 'pending'] });
    } catch (e) {
        actionMsg.value = 'Erreur : ' + errMsg(e);
        actionOk.value = false;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — contributions" description="Modération des contributions de l'Observatoire des ABC." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Contributions</h1>
            <span class="text-muted">modération des suggestions du public</span>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="t in tabs" :key="t.key" class="nav-item">
                <Link
                    class="nav-link d-flex align-items-center gap-2"
                    :class="{ active: statut === t.key }"
                    :href="`/admin/contributions?statut=${t.key}`"
                    :aria-current="statut === t.key ? 'page' : null"
                >
                    {{ t.label }}
                    <span v-if="counts[t.key] != null" class="badge rounded-pill" :class="statut === t.key ? 'text-bg-success' : 'text-bg-light'">{{ counts[t.key] }}</span>
                </Link>
            </li>
        </ul>

        <div v-if="actionMsg" class="small mb-2" :class="actionOk ? 'text-success' : 'text-danger'">{{ actionMsg }}</div>

        <div v-if="cards.length === 0" class="text-center text-muted py-5">Aucune contribution dans cette catégorie.</div>
        <div v-for="c in cards" :key="c.id" class="card shadow-sm mb-2">
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

        <Pagination :paginator="contributions" />

        <p class="foot">
            RGPD : l'adresse IP des contributeurs est conservée uniquement pour la modération des
            corrections et ne sert à aucun autre traitement.
        </p>
    </AdminLayout>
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
