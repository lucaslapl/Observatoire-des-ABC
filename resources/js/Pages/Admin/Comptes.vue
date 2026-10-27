<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import Pagination from '../../Components/Admin/Pagination.vue';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    comptes: { type: Object, required: true },
    q: { type: String, default: '' },
});

const comptesMsg = ref('');
const comptesOk = ref(true);
const comptesBusy = ref(false);

const recherche = ref(props.q);
let rechercheTimer = null;

// Recherche serveur (nom / email) : requête partielle, l'URL suit la
// recherche (partageable, marque-page fiable).
watch(recherche, (v) => {
    clearTimeout(rechercheTimer);
    rechercheTimer = setTimeout(() => {
        router.get('/admin/comptes', { q: v }, {
            only: ['comptes', 'q'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const formatDateFr = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleDateString('fr-FR') : '');

const errMsg = (e) => (e && e.response && e.response.data && (e.response.data.error || e.response.data.message)) || (e && e.message) || 'Erreur';

// Promotion / retrait du rôle modérateur.
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
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — comptes" description="Comptes des membres et gestion du rôle modérateur." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Comptes</h1>
            <span class="text-muted">membres et rôle modérateur</span>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2 py-2">
                <span class="fw-semibold">
                    Membres <span class="text-muted fw-normal">({{ comptes.total }})</span>
                </span>
                <div class="ms-auto">
                    <input v-model="recherche" type="search" class="form-control form-control-sm" style="min-width:220px"
                        placeholder="Rechercher un nom ou un email…" aria-label="Rechercher un compte" />
                </div>
            </div>
            <div class="card-body py-2">
                <div v-if="comptesMsg" class="small mb-2" :class="comptesOk ? 'text-success' : 'text-danger'">{{ comptesMsg }}</div>
                <div v-if="comptes.data.length === 0" class="text-muted small py-2">Aucun compte{{ q ? ' ne correspond à cette recherche' : '' }}.</div>
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
                            <tr v-for="u in comptes.data" :key="u.id">
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
                <Pagination :paginator="comptes" />
            </div>
            <div class="card-footer small text-muted py-2">
                Le modérateur valide les suggestions des contributeurs et enregistre des verdicts directs,
                sans accès à la maintenance du site.
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
.chip.admin { background: #fde8e8; color: #7f1d1d; }
.chip.moderateur { background: #e0f2fe; color: #075985; }
</style>
