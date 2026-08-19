<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    commune: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const c = computed(() => props.commune);

const crumbs = computed(() => {
    const list = [];
    if (c.value.region?.slug) {
        list.push({ label: c.value.region.label, url: `/region/${c.value.region.slug}` });
    }
    if (c.value.departement?.code) {
        list.push({ label: c.value.departement.label, url: `/departement/${c.value.departement.code}` });
    }
    list.push({ label: c.value.libelle });
    return list;
});

const statutClass = (s) =>
    ({ en_cours: 'text-bg-success', a_venir: 'text-bg-warning', termine: 'text-bg-primary', inconnu: 'text-bg-secondary' })[s] || 'text-bg-secondary';

const pageTitle = computed(() => `Atlas de la Biodiversité Communale ${c.value.libelle} (${c.value.departement?.code || ''})`);
const pageDescription = computed(() =>
    `Découvrez les Atlas de la Biodiversité Communale (ABC) sur la commune de ${c.value.libelle}, département ${c.value.departement?.label || ''}${c.value.region?.label ? ', région ' + c.value.region.label : ''}. ${c.value.n_projets} projet(s) ABC recensé(s).`
);

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    if (c.value.region?.slug) {
        items.push({ '@type': 'ListItem', position: 2, name: c.value.region.label, item: `${base}/region/${c.value.region.slug}` });
    }
    if (c.value.departement?.code) {
        items.push({ '@type': 'ListItem', position: items.length + 1, name: c.value.departement.label, item: `${base}/departement/${c.value.departement.code}` });
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: c.value.libelle });

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: items,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Place',
            name: `${c.value.libelle} (${c.value.departement?.code || ''})`,
            url: `${base}/commune/${c.value.code}`,
            address: {
                '@type': 'PostalAddress',
                addressLocality: c.value.libelle,
                addressRegion: c.value.departement?.label,
                addressCountry: 'FR',
            },
        },
    ];
});
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :jsonLd="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">Atlas de la Biodiversité Communale — {{ commune.libelle }}</h1>
            <p class="text-muted">
                Commune de {{ commune.libelle }}
                <template v-if="commune.departement?.label">, département {{ commune.departement.label }}<template v-if="commune.departement.code"> ({{ commune.departement.code.toUpperCase() }})</template></template>
                <template v-if="commune.region?.label">, région {{ commune.region.label }}</template>.
            </p>

            <h2 class="h5 mt-4">Les ABC de {{ commune.libelle }}</h2>
            <template v-if="commune.projets.length === 0">
                <p class="text-muted small">Aucun projet d'Atlas de la Biodiversité Communale recensé pour cette commune.</p>
            </template>
            <div v-else class="row row-cols-1 row-cols-md-2 g-3 mb-4">
                <div v-for="p in commune.projets" :key="p.projet_id" class="col">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6"><Link v-if="p.slug" class="text-reset text-decoration-none" :href="`/abc/${p.slug}`">{{ p.nom }}</Link><span v-else class="text-reset">{{ p.nom }}</span></h3>
                            <div class="d-flex flex-wrap gap-2 small">
                                <span class="badge" :class="statutClass(p.statut)">{{ p.statut_label }}</span>
                                <span class="badge text-bg-light">{{ p.source_label }}</span>
                                <span v-if="p.anomalie" class="badge text-bg-danger" title="Association de cette commune au projet à vérifier (écart géographique)">À vérifier</span>
                                <span v-if="p.annee_debut" class="text-muted">{{ p.annee_debut }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p><Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link></p>
        </div>
    </Layout>
</template>
