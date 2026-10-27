<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '../../Components/Admin/AdminLayout.vue';
import Seo from '../../Components/Seo.vue';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    pending: { type: Number, default: 0 },
    meta: { type: Object, default: null },
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

const sections = computed(() => {
    const base = [
        {
            href: '/admin/contributions',
            icon: 'bi-inboxes',
            label: 'Contributions',
            description: 'Suggestions du public à examiner, valider, refuser ou annuler.',
            badge: props.pending > 0 ? `${props.pending} à modérer` : null,
            badgeClass: 'text-bg-warning',
        },
        {
            href: '/admin/journaux',
            icon: 'bi-clock-history',
            label: 'Journaux d\u2019audit',
            description: 'Vérifications manuelles et fusions de doublons, avec leurs auteurs.',
            badge: null,
        },
    ];
    if (props.isAdmin) {
        base.push(
            {
                href: '/admin/actualites',
                icon: 'bi-newspaper',
                label: 'Actualités',
                description: 'Rédiger, publier ou masquer les actualités du site.',
                badge: null,
            },
            {
                href: '/admin/exclusions',
                icon: 'bi-slash-circle',
                label: 'Exclusions',
                description: 'Projets supprimés, jamais ré-importés au prochain collect.',
                badge: null,
            },
            {
                href: '/admin/comptes',
                icon: 'bi-people',
                label: 'Comptes',
                description: 'Membres du site et gestion du rôle modérateur.',
                badge: null,
            },
            {
                href: '/admin/maintenance',
                icon: 'bi-tools',
                label: 'Maintenance',
                description: 'Collecte des données, sauvegardes, état des sources.',
                badge: null,
            },
        );
    }
    return base;
});
</script>

<template>
    <AdminLayout :is-admin="isAdmin" :pending="pending">
        <Seo noindex title="Administration — tableau de bord" description="Panneau d'administration de l'Observatoire des ABC." />
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-4">
            <h1 class="h3 mb-0">{{ isAdmin ? 'Administration' : 'Modération' }}</h1>
            <span class="text-muted">tableau de bord</span>
        </div>

        <div class="row g-2 mb-4">
            <div v-for="card in statCards" :key="card.label" class="col-6 col-md-3 col-xl-3 col-xxl-2">
                <div class="card shadow-sm h-100">
                    <div class="card-body text-center py-3">
                        <div class="fs-4 fw-bold">{{ card.value }}</div>
                        <div class="small text-muted">{{ card.label }}</div>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="h5 mb-3">Sections</h2>
        <div class="row g-3">
            <div v-for="s in sections" :key="s.href" class="col-12 col-md-6 col-xxl-4">
                <Link :href="s.href" class="card shadow-sm h-100 text-decoration-none section-card">
                    <div class="card-body d-flex gap-3 align-items-start">
                        <i :class="['bi', s.icon, 'fs-4 text-success']" aria-hidden="true"></i>
                        <div>
                            <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap">
                                {{ s.label }}
                                <span v-if="s.badge" class="badge rounded-pill" :class="s.badgeClass">{{ s.badge }}</span>
                            </div>
                            <p class="small text-muted mb-0">{{ s.description }}</p>
                        </div>
                    </div>
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.section-card { color: inherit; transition: box-shadow .15s ease-in-out, transform .15s ease-in-out; }
.section-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; transform: translateY(-1px); }
</style>
