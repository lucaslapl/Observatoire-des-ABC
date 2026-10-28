<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    znieff: { type: Object, required: true },
});

const z = computed(() => props.znieff);

const crumbs = [
    { label: 'Patrimoine écologique', url: '/patrimoine' },
    { label: 'ZNIEFF', url: '/patrimoine/znieff' },
    { label: z.value.nom },
];

const fmtNb = (v, decimales = 0) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: decimales });

/* Coupe une description au dernier mot complet sous la limite, avec
   ellipse (les meta descriptions sont tronquées vers ~155 caractères). */
const tronque = (t, max = 155) =>
    t.length <= max ? t : `${t.slice(0, max).replace(/\s+\S*$/, '')}…`;

const pageTitle = computed(() => `ZNIEFF ${z.value.nom} (${z.value.id_national})`);
const pageDescription = computed(() => tronque(z.value.chapeau));

const jsonLd = computed(() => [
    {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: [
            { '@type': 'ListItem', position: 1, name: 'Accueil', item: '/' },
            { '@type': 'ListItem', position: 2, name: 'Patrimoine écologique', item: '/patrimoine' },
            { '@type': 'ListItem', position: 3, name: 'ZNIEFF', item: '/patrimoine/znieff' },
            { '@type': 'ListItem', position: 4, name: z.value.nom },
        ],
    },
    {
        '@context': 'https://schema.org',
        '@type': 'Place',
        name: `ZNIEFF ${z.value.nom}`,
        description: z.value.chapeau,
    },
]);
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :json-ld="jsonLd" />
        <div class="container py-4 patrimoine-fiche">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">{{ z.nom }}</h1>
            <p class="text-muted">
                ZNIEFF de type {{ z.ty_zone }} · identifiant national {{ z.id_national }}
                <template v-if="z.surface_ha"> · {{ fmtNb(z.surface_ha, 0) }} ha</template>
                <template v-if="z.altitude_min !== null && z.altitude_max !== null"> · {{ z.altitude_min }}–{{ z.altitude_max }} m d'altitude</template>
                <template v-if="z.parent">
                    — incluse dans <Link class="text-decoration-none" :href="`/patrimoine/znieff/${z.parent.id_national}`">{{ z.parent.nom }}</Link>
                </template>
            </p>

            <p class="mb-3">{{ z.chapeau }}</p>

            <div v-if="z.texte_delimitation || z.texte_bilan" class="card my-3 bg-body-tertiary border-0">
                <div class="card-body">
                    <h2 class="h6">Délimitation et bilan</h2>
                    <div v-if="z.texte_delimitation" class="text-break"><h3 class="h6 mt-2">Délimitation</h3><div v-html="z.texte_delimitation"></div></div>
                    <div v-if="z.texte_bilan" class="text-break mt-2"><h3 class="h6 mt-2">Bilan</h3><div v-html="z.texte_bilan"></div></div>
                </div>
            </div>

            <div v-if="z.texte_interet" class="my-3">
                <h2 class="h6">Intérêt écologique</h2>
                <div class="small text-break" v-html="z.texte_interet"></div>
            </div>

            <div class="row g-3 my-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h2 class="h6">Communes ABC concernées</h2>
                            <p class="small text-muted">
                                {{ z.communes.length }} commune(s) ayant un ABC sur les {{ z.nb_communes_total }}
                                commune(s) de la zone.
                            </p>
                            <p>
                                <Link
                                    v-for="c in z.communes"
                                    :key="c.code"
                                    class="badge text-bg-light text-decoration-none me-1 mb-1"
                                    :href="`/commune/${c.code}`"
                                    :title="c.libelle"
                                >{{ c.libelle }}</Link>
                            </p>
                        </div>
                    </div>
                </div>
                <div v-if="z.enfants.length" class="col-12 col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h2 class="h6">Znieff de type 1 incluses</h2>
                            <ul class="list-unstyled small mb-0">
                                <li v-for="e in z.enfants" :key="e.id_national" class="mb-1">
                                    <Link class="text-decoration-none" :href="`/patrimoine/znieff/${e.id_national}`">{{ e.nom }}</Link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="z.groupes_especes.length" class="my-3">
                <h2 class="h6">Espèces déterminantes citées</h2>
                <p class="small text-muted">
                    Espèces ayant justifié l'inscription de la zone à l'inventaire ZNIEFF (hors espèces
                    confidentielles).
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle small">
                        <tbody>
                            <template v-for="g in z.groupes_especes" :key="g.groupe">
                                <tr>
                                    <th colspan="2" class="table-light">
                                        {{ g.groupe }} <span class="badge text-bg-success">{{ g.n }}</span>
                                    </th>
                                </tr>
                                <tr v-for="nom in g.noms" :key="nom">
                                    <td class="ps-4">{{ nom }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="small text-muted mt-4">
                Fiche établie à partir de l'inventaire national ZNIEFF de l'
                <a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>
                (MNHN)<template v-if="z.versions.znieff"> — {{ z.versions.znieff }}</template>.
                <Link class="text-decoration-none" href="/methodologie">Méthodologie et licences.</Link>
            </p>
            <p><Link class="btn btn-outline-success btn-sm" href="/patrimoine/znieff">← Toutes les ZNIEFF</Link></p>
        </div>
    </Layout>
</template>
