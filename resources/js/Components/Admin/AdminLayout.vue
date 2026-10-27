<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Layout from '../Layout.vue';

const props = defineProps({
    isAdmin: { type: Boolean, default: false },
    // Contributions en attente : badge sur l'entrée « Contributions ».
    pending: { type: Number, default: 0 },
});

const items = computed(() => {
    const base = [
        { component: 'Admin/Dashboard', href: '/admin', label: 'Tableau de bord', icon: 'bi-speedometer2' },
        { component: 'Admin/Contributions', href: '/admin/contributions', label: 'Contributions', icon: 'bi-inboxes' },
        { component: 'Admin/Journaux', href: '/admin/journaux', label: 'Journaux d\u2019audit', icon: 'bi-clock-history' },
    ];
    if (props.isAdmin) {
        base.push(
            { component: 'Admin/Actualites', href: '/admin/actualites', label: 'Actualités', icon: 'bi-newspaper' },
            { component: 'Admin/Exclusions', href: '/admin/exclusions', label: 'Exclusions', icon: 'bi-slash-circle' },
            { component: 'Admin/Comptes', href: '/admin/comptes', label: 'Comptes', icon: 'bi-people' },
            { component: 'Admin/Maintenance', href: '/admin/maintenance', label: 'Maintenance', icon: 'bi-tools' },
        );
    }
    return base;
});
</script>

<template>
    <Layout>
        <div class="container py-4">
            <div class="row g-4">
                <aside class="col-lg-3 col-xxl-2">
                    <nav class="admin-nav card shadow-sm" aria-label="Sections du panneau">
                        <div class="list-group list-group-flush">
                            <Link
                                v-for="item in items"
                                :key="item.href"
                                :href="item.href"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                                :class="{ active: $page.component === item.component }"
                                :aria-current="$page.component === item.component ? 'page' : null"
                            >
                                <i :class="['bi', item.icon]" aria-hidden="true"></i>
                                <span>{{ item.label }}</span>
                                <span v-if="item.component === 'Admin/Contributions' && pending > 0" class="badge text-bg-warning rounded-pill ms-auto">{{ pending }}</span>
                            </Link>
                        </div>
                        <div class="card-footer small text-muted py-2 d-flex align-items-center gap-2">
                            <i class="bi bi-person-lock" aria-hidden="true"></i>{{ isAdmin ? 'Administrateur' : 'Modérateur' }}
                        </div>
                    </nav>
                </aside>
                <div class="col-lg-9 col-xxl-10">
                    <slot />
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
/* Le menu reste visible pendant le défilement des longues listes. */
@media (min-width: 992px) {
    .admin-nav { position: sticky; top: 1rem; }
}
</style>
