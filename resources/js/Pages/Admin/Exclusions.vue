<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import Pagination from '../../Components/Admin/Pagination.vue';

defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    exclusions: { type: Object, required: true },
});

const exclusionsMsg = ref('');
const exclusionsOk = ref(true);
const exclusionsBusy = ref(false);

const formatDateFr = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleDateString('fr-FR') : '');

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

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
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — exclusions" description="Projets exclus, jamais ré-importés au collect." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Exclusions</h1>
            <span class="text-muted">projets supprimés, non ré-importés au collect</span>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                <span class="fw-semibold">
                    Projets exclus <span class="text-muted fw-normal">({{ exclusions.total }})</span>
                </span>
                <button class="btn btn-sm btn-outline-secondary ms-auto" :disabled="exclusionsBusy" @click="exportAudit"><i class="bi bi-download me-1" aria-hidden="true"></i>Exporter le journal d'audit</button>
            </div>
            <div class="card-body py-2">
                <div v-if="exclusionsMsg" class="small mb-2" :class="exclusionsOk ? 'text-success' : 'text-danger'">{{ exclusionsMsg }}</div>
                <div v-if="exclusions.data.length === 0" class="text-muted small py-2">
                    Aucune exclusion. Les projets supprimés depuis la carte (admin) apparaissent ici et ne sont pas ré-importés au prochain collect.
                </div>
                <ul v-else class="list-group list-group-flush">
                    <li v-for="e in exclusions.data" :key="e.projet_id"
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
                <Pagination :paginator="exclusions" />
            </div>
            <div class="card-footer small text-muted py-2">La levée d'exclusion ne recrée pas le projet : il faut ensuite lancer un collect.</div>
        </div>
    </AdminLayout>
</template>
