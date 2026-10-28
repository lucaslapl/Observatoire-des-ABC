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
    { label: 'Patrimoine écologique', url: '/patrimoine' },
    { label: 'Sites Natura 2000', url: '/patrimoine/natura' },
    { label: s.value.nom || s.value.sitecode },
];

const typeLabel = computed(() => (s.value.type_site === 'ZPS' ? 'ZPS — zone de protection spéciale' : 'ZSC — zone spéciale de conservation'));

/* Statuts biologiques du formulaire standard (FSD) : r = reproduction,
   c = concentration, w = hivernage, p = passage/migration. */
const presenceLabel = (p) =>
    p
        ? p
              .split(',')
              .map((x) => ({ r: 'nicheuse', c: 'concentration', w: 'hivernante', p: 'de passage' })[x] || x)
              .join(', ')
        : null;

/* Notations du formulaire standard pour les habitats (annexe I de la
   directive Habitats). Tooltips + légende affichée sous le tableau. */
const repLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'significative', D: 'non significative' })[l] || null;
const conLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'moyenne' })[l] || null;
const evalLabel = (l) => ({ A: 'excellente', B: 'bonne', C: 'significative', D: 'non significative' })[l] || null;

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
            <h1 class="h3 mb-2">{{ s.nom || 'Site Natura 2000 ' + s.sitecode }}</h1>
            <p v-if="!s.nom" class="small text-muted">
                Ce site est identifié par son code officiel : la base INPN utilisée (déc. 2024) ne publie
                le nom que pour une partie des sites Natura 2000.
            </p>
            <p class="text-muted">
                <span class="badge text-bg-light">{{ typeLabel }}</span>
                · code {{ s.sitecode }}
                · réseau européen Natura 2000, directives « Habitats » et « Oiseaux »
            </p>

            <div v-if="s.pdf_local || s.docob.length" class="my-3">
                <h2 class="h6">Document d'objectifs</h2>
                <template v-if="s.pdf_local">
                    <p class="small text-muted">Document d'objectifs (DOCOB) consultable — tel que transmis dans la base INPN (décembre 2024), il peut depuis avoir été révisé :</p>
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
                <p class="small text-muted">
                    Habitats de l'annexe I de la directive Habitats recensés sur le site (formulaire standard de données).
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle small">
                        <thead>
                            <tr>
                                <th>Habitat</th>
                                <th>Corine</th>
                                <th>Représentativité</th>
                                <th>Conservation</th>
                                <th>Éval. globale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in s.habitats" :key="h.cd_ue + (h.cd_hab || '')">
                                <td class="habitat-nom">
                                    <span class="badge text-bg-light">{{ h.cd_ue }}</span>
                                    <span v-if="h.prioritaire" class="badge text-bg-warning" title="Habitat prioritaire (directive Habitats)">prioritaire</span>
                                    <span v-if="h.nom"> {{ h.nom }}</span>
                                </td>
                                <td class="text-muted">{{ h.cd_hab || '—' }}</td>
                                <td>
                                    <span v-if="h.representativite" class="badge text-bg-light" :title="`Représentativité ${repLabel(h.representativite)}`">{{ h.representativite }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span v-if="h.conservation" class="badge text-bg-light" :title="`Conservation (structure et fonctions) ${conLabel(h.conservation)}`">{{ h.conservation }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <span v-if="h.evaluation_globale" class="badge text-bg-light" :title="`Évaluation globale ${evalLabel(h.evaluation_globale)}`">{{ h.evaluation_globale }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">
                    <strong>Lecture</strong> — <strong>Habitat</strong> : le code à quatre chiffres (ex. 2110) est le
                    code EUR de l'annexe I de la directive Habitats, suivi du nom de l'habitat ; « prioritaire »
                    signale un habitat prioritaire au sens de la directive. <strong>Corine</strong> : code de la
                    classification européenne CORINE Biotopes (rattachement antérieur de l'habitat).
                    <strong>Représentativité</strong> : A excellente, B bonne, C significative, D non significative.
                    <strong>Conservation</strong> (structure et fonctions) : A excellente, B bonne, C moyenne.
                    <strong>Évaluation globale</strong> du site pour cet habitat : A excellente, B bonne,
                    C significative, D non significative.
                </p>
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
                                <td>
                                    <em>{{ e.nom_scientifique || '—' }}</em>
                                    <span v-if="presenceLabel(e.presences)" class="text-muted small"> ({{ presenceLabel(e.presences) }})</span>
                                </td>
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
                (MNHN)<template v-if="s.versions.natura"> — {{ s.versions.natura }}</template>.
                <Link class="text-decoration-none" href="/methodologie">Méthodologie et licences.</Link>
            </p>
            <p><Link class="btn btn-outline-success btn-sm" href="/patrimoine/natura">← Tous les sites Natura 2000</Link></p>
        </div>
    </Layout>
</template>

<style scoped>
/* Les noms d'habitats (annexe I) sont longs : la colonne est bornée
   pour les faire passer à la ligne et laisser respirer les notations. */
.habitat-nom {
    max-width: 26rem;
    min-width: 16rem;
}
</style>
