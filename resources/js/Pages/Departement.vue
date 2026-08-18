<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    departement: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const d = computed(() => props.departement);

const crumbs = computed(() => {
    const list = [];
    if (d.value.regions?.length === 1) {
        list.push({ label: d.value.regions[0].label, url: `/region/${d.value.regions[0].slug}` });
    } else if (d.value.regions?.length > 1) {
        d.value.regions.forEach((r) => list.push({ label: r.label, url: `/region/${r.slug}` }));
    }
    list.push({ label: d.value.label });
    return list;
});

const statutClass = (s) =>
    ({ en_cours: 'text-bg-success', a_venir: 'text-bg-warning', termine: 'text-bg-primary', inconnu: 'text-bg-secondary' })[s] || 'text-bg-secondary';

const pageTitle = computed(() => `Atlas de la Biodiversité Communale — ${d.value.label}`);
const pageDescription = computed(() =>
    `Les Atlas de la Biodiversité Communale (ABC) du département ${d.value.label} (${d.value.code.toUpperCase()}) : ${d.value.n_projets} projet(s) ABC sur ${d.value.n_communes} commune(s).`
);

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    if (d.value.regions?.length === 1) {
        items.push({ '@type': 'ListItem', position: 2, name: d.value.regions[0].label, item: `${base}/region/${d.value.regions[0].slug}` });
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: d.value.label });

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: items,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'AdministrativeArea',
            name: `${d.value.label} (${d.value.code.toUpperCase()})`,
            url: `${base}/departement/${d.value.code}`,
        },
    ];
});
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :jsonLd="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">ABC dans le département {{ departement.label }}</h1>
            <p class="text-muted mb-4">
                {{ departement.n_projets }} projet(s) d'Atlas de la Biodiversité Communale sur {{ departement.n_communes }} commune(s)
                <template v-if="departement.regions?.length"> — {{ departement.regions.map((r) => r.label).join(', ') }}</template>.
            </p>

            <h2 class="h5">Projets du département</h2>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Projet</th>
                            <th class="text-nowrap">Commune principale</th>
                            <th class="text-nowrap">Début</th>
                            <th>Statut</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in departement.projets" :key="p.projet_id">
                            <td><Link v-if="p.slug" class="text-reset" :href="`/abc/${p.slug}`">{{ p.nom }}</Link><span v-else class="text-reset">{{ p.nom }}</span></td>
                            <td class="text-muted small">{{ p.commune_principale }}</td>
                            <td class="small">{{ p.annee_debut || '—' }}</td>
                            <td><span class="badge" :class="statutClass(p.statut)">{{ p.statut_label }}</span></td>
                            <td class="text-muted small">{{ p.source_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2 class="h5">Communes du département</h2>
            <div v-if="departement.communes.length === 0" class="text-muted small">Aucune commune répertoriée.</div>
            <div v-else class="d-flex flex-wrap gap-2 mb-4">
                <Link v-for="c in departement.communes" :key="c.code" :href="`/commune/${c.code}`" class="list-group-item border rounded-3 px-3 py-2 small text-decoration-none text-reset">
                    {{ c.libelle }}
                    <template v-if="c.n > 1"><small class="text-muted"> · {{ c.n }} ABC</small></template>
                    <template v-else><small class="text-muted"> · {{ c.n }} ABC</small></template>
                </Link>
            </div>

            <p class="d-flex flex-wrap gap-2">
                <Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link>
            </p>
        </div>
    </Layout>
</template>
