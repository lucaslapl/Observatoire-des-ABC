<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    listing: { type: Object, required: true },
});

const crumbs = [
    { label: 'Patrimoine écologique', url: '/patrimoine' },
    { label: 'ZNIEFF' },
];

const fmtNb = (v, decimales = 0) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: decimales });

const pageTitle = computed(() => `ZNIEFF des communes ABC — page ${props.listing.page}`);
const pageDescription = computed(() =>
    `Liste des ${props.listing.total.toLocaleString('fr-FR')} ZNIEFF (zones naturelles d'intérêt écologique, faunistique et floristique) touchant les communes ayant un Atlas de la Biodiversité Communale.`
);

const pageUrl = (n) => (n === 1 ? '/patrimoine/znieff' : `/patrimoine/znieff?page=${n}`);
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">ZNIEFF des communes ABC</h1>
            <p class="text-muted">
                {{ listing.total.toLocaleString('fr-FR') }} zones naturelles d'intérêt écologique, faunistique et
                floristique (inventaire <a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>)
                touchent au moins une commune ayant un ABC.
            </p>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle small">
                    <thead>
                        <tr><th>Nom</th><th>Type</th><th class="text-end">Surface (ha)</th><th class="text-end">Communes ABC concernées</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="z in listing.items" :key="z.id_national">
                            <td><Link class="text-reset text-decoration-none" :href="`/patrimoine/znieff/${z.id_national}`">{{ z.nom }}</Link></td>
                            <td><span class="badge text-bg-light" :title="z.ty_zone === 1 ? 'Secteur de grand intérêt biologique' : 'Grand ensemble naturel riche'">Type {{ z.ty_zone }}</span></td>
                            <td class="text-end">{{ z.surface_ha ? fmtNb(z.surface_ha, 0) : '—' }}</td>
                            <td class="text-end">{{ z.nb_communes_abc }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="listing.pages > 1" aria-label="Pagination ZNIEFF">
                <ul class="pagination pagination-sm">
                    <li class="page-item" :class="{ disabled: listing.page <= 1 }">
                        <Link class="page-link" :href="pageUrl(listing.page - 1)" :aria-disabled="listing.page <= 1">Précédent</Link>
                    </li>
                    <li class="page-item disabled"><span class="page-link">Page {{ listing.page }} / {{ listing.pages }}</span></li>
                    <li class="page-item" :class="{ disabled: listing.page >= listing.pages }">
                        <Link class="page-link" :href="pageUrl(listing.page + 1)">Suivant</Link>
                    </li>
                </ul>
            </nav>
        </div>
    </Layout>
</template>
