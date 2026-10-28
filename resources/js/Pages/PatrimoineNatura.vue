<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    site: { type: Object, required: true },
});

const s = computed(() => props.site);

const crumbs = [
    { label: 'Accueil', url: '/' },
    { label: 'Patrimoine écologique', url: '/patrimoine' },
    { label: 'Sites Natura 2000', url: '/patrimoine/natura' },
    { label: s.value.nom || s.value.sitecode },
];

const typeLabel = computed(() => (s.value.type_site === 'ZPS' ? 'ZPS — zone de protection spéciale' : 'ZSC — zone spéciale de conservation'));

const pageTitle = computed(() => `Site Natura 2000 ${s.value.nom || s.value.sitecode} (${s.value.sitecode})`);
const pageDescription = computed(() =>
    `Site Natura 2000 ${typeLabel.value.split(' — ')[0]} « ${s.value.nom || s.value.sitecode} » : habitats et espèces de la directive Habitats, communes ABC concernées, d'après la base INPN.`
);

const jsonLd = computed(() => [
    {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: [
            { '@type': 'ListItem', position: 1, name: 'Accueil', item: '/' },
            { '@type': 'ListItem', position: 2, name: 'Patrimoine écologique', item: '/patrimoine' },
            { '@type': 'ListItem', position: 3, name: 'Sites Natura 2000', item: '/patrimoine/natura' },
            { '@type': 'ListItem', position: 4, name: s.value.nom || s.value.sitecode },
        ],
    },
]);
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :json-ld="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">{{ s.nom || 'Site Natura 2000' }}</h1>
            <p class="text-muted">
                <span class="badge text-bg-light">{{ typeLabel }}</span>
                · code {{ s.sitecode }}
                · réseau européen Natura 2000, directives « Habitats » et « Oiseaux »
            </p>

            <div v-if="s.pdf_local || s.docob.length" class="my-3">
                <h2 class="h6">Document d'objectifs</h2>
                <template v-if="s.pdf_local">
                    <p class="small text-muted">Document d'objectifs (DOCOB) consultable :</p>
                    <p><a class="btn btn-outline-success btn-sm" :href="s.pdf_local" target="_blank" rel="noopener">Ouvrir le DOCOB (PDF)</a></p>
                </template>
                <template v-else>
                    <p class="small text-muted">
                        Document(s) d'objectifs référencé(s) dans la base INPN :
                    </p>
                    <ul class="small">
                        <li v-for="(d, i) in s.docob" :key="i">
                            <a v-if="d.lien" :href="d.lien" target="_blank" rel="noopener">{{ d.titre }}</a>
                            <template v-else>{{ d.titre }}</template>
                        </li>
                    </ul>
                    <p class="small text-muted">Document hébergé par l'INPN — indisponible si la plateforme INPN est en maintenance.</p>
                </template>
            </div>

            <div class="card my-3 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Communes ABC concernées</h2>
                    <p class="small text-muted">
                        {{ s.communes.length }} commune(s) ayant un ABC sur les {{ s.nb_communes_total }} commune(s)
                        du site.
                    </p>
                    <p>
                        <Link
                            v-for="c in s.communes"
                            :key="c.code"
                            class="badge text-bg-light text-decoration-none me-1 mb-1"
                            :href="`/commune/${c.code}`"
                            :title="c.libelle"
                        >{{ c.libelle }}</Link>
                    </p>
                </div>
            </div>

            <div v-if="s.habitats.length" class="my-3">
                <h2 class="h6">Habitats d'intérêt communautaire</h2>
                <p class="small text-muted">Codes EUR de la directive Habitats, avec représentativité et état de conservation (formulaire standard de données).</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle small">
                        <thead>
                            <tr><th>Code EUR</th><th>Corine</th><th>Représentativité</th><th>Conservation</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in s.habitats" :key="h.cd_ue + (h.cd_hab || '')">
                                <td>{{ h.cd_ue }}</td>
                                <td class="text-muted">{{ h.cd_hab || '—' }}</td>
                                <td>{{ h.representativite || '—' }}</td>
                                <td>{{ h.conservation || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="s.especes.length" class="my-3">
                <h2 class="h6">Espèces des directives</h2>
                <p class="small text-muted">
                    Espèces recensées sur le site : oiseaux (directive « Oiseaux », annexe I) et autres
                    espèces (directive « Habitats », annexe II, éventuellement prioritaires).
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle small">
                        <thead>
                            <tr><th>Espèce</th><th>Nom vernaculaire</th><th>Directive</th><th>Annexe</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(e, i) in s.especes" :key="i">
                                <td><em>{{ e.nom_scientifique || '—' }}</em></td>
                                <td>{{ e.nom_vernaculaire || '—' }}</td>
                                <td><span class="badge text-bg-light">{{ e.code_n2000.startsWith('A') ? 'Oiseaux' : 'Habitats' }}</span></td>
                                <td>
                                    <template v-if="e.code_n2000.startsWith('A')">
                                        <span v-if="e.annexe_1" class="badge text-bg-success">I</span>
                                        <span v-else class="text-muted">—</span>
                                    </template>
                                    <template v-else>
                                        <span v-if="e.annexe_ii" class="badge text-bg-success">II</span>
                                        <span v-if="e.prioritaire" class="badge text-bg-warning">Prioritaire</span>
                                        <span v-if="!e.annexe_ii && !e.prioritaire" class="text-muted">—</span>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="small text-muted mt-4">
                Fiche établie à partir de la base Natura 2000 de l'
                <a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>
                (MNHN). <Link class="text-decoration-none" href="/methodologie">Méthodologie et licences.</Link>
            </p>
            <p><Link class="btn btn-outline-success btn-sm" href="/patrimoine/natura">← Tous les sites Natura 2000</Link></p>
        </div>
    </Layout>
</template>
