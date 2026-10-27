<script setup>
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';
import Pagination from '../../Components/Admin/Pagination.vue';
import { VERDICT } from '../../map/constants.js';

defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    journalVerifications: { type: Object, required: true },
    journalFusions: { type: Object, required: true },
});

const verdictLabel = (v) => (VERDICT[v] || {}).label || v;
const formatDateTimeFr = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('fr-FR') : '');
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — journaux d'audit" description="Journal des vérifications manuelles et des fusions de doublons." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h3 mb-0">Journaux d'audit</h1>
            <span class="text-muted">qui a tranché quoi, et quand</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-person-check me-1" aria-hidden="true"></i>
                Vérifications manuelles <span class="text-muted fw-normal">({{ journalVerifications.total }})</span>
            </div>
            <div class="card-body py-2">
                <div v-if="journalVerifications.data.length === 0" class="text-muted small py-2">
                    Aucune vérification manuelle enregistrée pour l'instant. Les verdicts posés depuis la page
                    de vérification (/verify) apparaissent ici, avec leur auteur.
                </div>
                <ul v-else class="list-group list-group-flush">
                    <li v-for="(v, i) in journalVerifications.data" :key="i" class="list-group-item px-0 py-2">
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
                <Pagination :paginator="journalVerifications" page-param="vpage" />
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="bi bi-union me-1" aria-hidden="true"></i>
                Fusions de doublons <span class="text-muted fw-normal">({{ journalFusions.total }})</span>
            </div>
            <div class="card-body py-2">
                <div v-if="journalFusions.data.length === 0" class="text-muted small py-2">
                    Aucune fusion enregistrée pour l'instant. Les doublons fusionnés depuis la page de
                    vérification (/verify) ou la commande abc:merge-projects apparaissent ici, avec leur
                    auteur et le projet canonique retenu.
                </div>
                <ul v-else class="list-group list-group-flush">
                    <li v-for="(f, i) in journalFusions.data" :key="i" class="list-group-item px-0 py-2">
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
                <Pagination :paginator="journalFusions" page-param="fpage" />
            </div>
        </div>

        <div class="small text-muted mt-3">
            Le doublon devient un alias : son slug redirige en 301 vers le canonique et il n'est
            pas réimporté au collect. Un snapshot de rollback est écrit à chaque fusion.
        </div>
    </AdminLayout>
</template>
