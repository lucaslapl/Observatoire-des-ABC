<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    region: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const r = computed(() => props.region);

const crumbs = computed(() => [{ label: r.value.label }]);

const pageTitle = computed(() => `Atlas de la Biodiversité Communale — région ${r.value.label}`);
const pageDescription = computed(() =>
    `Les Atlas de la Biodiversité Communale (ABC) en région ${r.value.label} : ${r.value.n_projets} projet(s) sur ${r.value.n_communes} commune(s), dans ${r.value.n_departements} département(s).`
);

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    items.push({ '@type': 'ListItem', position: 2, name: r.value.label });

    return [
        { '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: items },
        { '@context': 'https://schema.org', '@type': 'Place', name: `Région ${r.value.label}`, url: `${base}/region/${r.value.slug}` },
    ];
});
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :jsonLd="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">Les ABC en région {{ region.label }}</h1>
            <p class="text-muted mb-4">
                {{ region.n_projets }} projet(s) d'Atlas de la Biodiversité Communale dans la région {{ region.label }},
                sur {{ region.n_communes }} commune(s) et {{ region.n_departements }} département(s).
            </p>

            <h2 class="h5">Départements de la région</h2>
            <div v-if="region.departements.length === 0" class="text-muted small">Aucune donnée répertoriée.</div>
            <div v-else class="d-flex flex-wrap gap-2 mb-4">
                <Link v-for="d in region.departements" :key="d.code" :href="`/departement/${d.code}`" class="list-group-item border rounded-3 px-3 py-2 small text-decoration-none text-reset">
                    {{ d.label }} <small class="text-muted">· {{ d.n }} ABC</small>
                </Link>
            </div>

            <p><Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link></p>
        </div>
    </Layout>
</template>
