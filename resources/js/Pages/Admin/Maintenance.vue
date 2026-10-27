<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import { STATUT_LABEL, SRC_LABEL } from '../../map/constants.js';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    meta: { type: Object, default: null },
});

const busy = ref(false);
const maintenanceMsg = ref('');
const maintenanceOk = ref(true);

const statutLabel = (s) => STATUT_LABEL[s] || s;
const sourceLabel = (s) => SRC_LABEL[s] || s;

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

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
        router.reload({ only: ['meta', 'pending'] });
    } catch (e) {
        maintenanceMsg.value = 'Erreur : ' + errMsg(e);
        maintenanceOk.value = false;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — maintenance" description="Collecte des données, sauvegardes et état des sources." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Maintenance</h1>
            <span class="text-muted">collecte, sauvegarde, sources</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-2">Opérations</div>
            <div class="card-body py-3">
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <button class="btn btn-primary btn-sm" :disabled="busy" @click="collect"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Collecter les données</button>
                    <button class="btn btn-secondary btn-sm" :disabled="busy" @click="backup"><i class="bi bi-save me-1" aria-hidden="true"></i>Sauvegarder la base</button>
                    <span v-if="busy" class="spinner-border spinner-border-sm text-success" role="status"></span>
                </div>
                <div v-if="maintenanceMsg" class="small mt-2" :class="maintenanceOk ? 'text-success' : 'text-danger'">{{ maintenanceMsg }}</div>
                <div class="small text-muted mt-2">
                    La collecte recharge les 4 sources (data.gouv, Wayback, Fonds vert) puis recalcule statuts,
                    géocodage et anomalies. Le travail humain (vérifications, contributions) est toujours préservé.
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
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
</style>
