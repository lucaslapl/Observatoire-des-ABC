<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    stats: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const crumbs = [{ label: 'Patrimoine écologique' }];

const pageTitle = computed(() => 'Patrimoine écologique INPN — ZNIEFF et Natura 2000 des communes ABC');
const pageDescription = computed(() =>
    `Inventaires nationaux du patrimoine naturel (INPN) en regard des Atlas de la Biodiversité Communale : ${props.stats.nb_znieff} ZNIEFF et ${props.stats.nb_natura} sites Natura 2000 touchent les communes ayant un ABC.`
);

const jsonLd = computed(() => [
    {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: [
            { '@type': 'ListItem', position: 1, name: 'Accueil', item: (site.value.url || '') + '/' },
            { '@type': 'ListItem', position: 2, name: 'Patrimoine écologique' },
        ],
    },
]);
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :jsonLd="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">Patrimoine écologique des communes ABC</h1>
            <p class="text-muted">
                Les inventaires nationaux du patrimoine naturel
                (<a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>, Muséum national d'Histoire
                naturelle) dressent l'état des connaissances sur les zonages d'intérêt écologique. Cet Observatoire
                les met en regard des Atlas de la Biodiversité Communale : un ABC est souvent l'occasion de découvrir
                ou d'approfondir ces zonages.
            </p>

            <div class="row g-3 my-4">
                <div class="col-12 col-md-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h2 class="h5">ZNIEFF</h2>
                            <p class="card-text small text-muted">
                                Zones naturelles d'intérêt écologique, faunistique et floristique.
                                <template v-if="stats.versions.znieff">Base : {{ stats.versions.znieff }}.</template>
                            </p>
                            <p class="display-6">{{ stats.nb_znieff.toLocaleString('fr-FR') }}</p>
                            <p class="small text-muted">zonages touchent les communes ayant un ABC</p>
                            <Link class="btn btn-outline-success btn-sm" href="/patrimoine/znieff">Explorer les ZNIEFF</Link>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h2 class="h5">Sites Natura 2000</h2>
                            <p class="card-text small text-muted">
                                Zones spéciales de conservation et zones de protection spéciale.
                                <template v-if="stats.versions.natura">Base : {{ stats.versions.natura }}.</template>
                            </p>
                            <p class="display-6">{{ stats.nb_natura.toLocaleString('fr-FR') }}</p>
                            <p class="small text-muted">sites touchent les communes ayant un ABC</p>
                            <Link class="btn btn-outline-success btn-sm" href="/patrimoine/natura">Explorer les sites Natura 2000</Link>
                        </div>
                    </div>
                </div>
            </div>

            <p class="small text-muted">
                Ces pages reprennent des faits bruts des référentiels INPN (zonages, surfaces, comptages) sans en
                dériver d'indicateur. Les espèces dites « confidentielles » dans le référentiel ne sont jamais
                affichées. Détails et licences :
                <Link class="text-decoration-none" href="/methodologie">méthodologie</Link>.
            </p>
        </div>
    </Layout>
</template>
