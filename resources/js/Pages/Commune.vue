<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    commune: { type: Object, required: true },
    patrimoine: { type: Object, default: null },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const c = computed(() => props.commune);
const patrimoine = computed(() => props.patrimoine);

/* Nombre au format français (espace fine pour les milliers, virgule décimale). */
const fmtNb = (v, decimales = 0) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: decimales });

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
    ({ en_cours: 'badge-st-en_cours', a_venir: 'badge-st-a_venir', termine: 'badge-st-termine', inconnu: 'badge-st-inconnu' })[s] || 'badge-st-inconnu';

const pageTitle = computed(() => `ABC ${c.value.libelle} (${c.value.departement?.code?.toUpperCase() || ''})`);
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
            <p v-if="commune.demographie" class="small text-muted">
                {{ fmtNb(commune.demographie.population) }} habitants<template v-if="commune.demographie.surface_km2"> · {{ fmtNb(commune.demographie.surface_km2, 2) }} km²</template><template v-if="commune.demographie.densite"> · {{ fmtNb(commune.demographie.densite, 1) }} hab./km²</template> — population légale (recensement <a href="https://www.insee.fr" target="_blank" rel="noopener">INSEE</a>), superficie (IGN), via <a href="https://geo.api.gouv.fr" target="_blank" rel="noopener">geo.api.gouv.fr</a>.
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

            <template v-if="patrimoine?.present">
                <h2 class="h5 mt-4">Patrimoine écologique référencé</h2>
                <p class="small text-muted">
                    Inventaires nationaux du patrimoine naturel (<a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>)
                    touchant la commune : informations factuelles telles que publiées dans les référentiels
                    <template v-if="patrimoine.versions.znieff">— ZNIEFF : {{ patrimoine.versions.znieff.toLowerCase() }}</template>
                    <template v-if="patrimoine.versions.natura">, Natura 2000 : {{ patrimoine.versions.natura.toLowerCase() }}</template>
                    <template v-if="patrimoine.versions.aires">, aires protégées : {{ patrimoine.versions.aires.toLowerCase() }}</template>.
                    Méthode détaillée : <Link class="text-decoration-none" href="/methodologie">méthodologie</Link>.
                </p>

                <div v-if="patrimoine.znieff.length" class="mb-3">
                    <h3 class="h6">ZNIEFF</h3>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Nom</th><th>Type</th><th class="text-end">Surface (ha)</th><th class="text-end">Espèces déterminantes</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="z in patrimoine.znieff" :key="z.id">
                                    <td><Link class="text-reset text-decoration-none" :href="`/patrimoine/znieff/${z.id_national}`" :title="`Fiche ZNIEFF ${z.nom}`">{{ z.nom }}</Link> <span class="text-muted">({{ z.id_national }})</span></td>
                                    <td><span class="badge text-bg-light" :title="z.ty_zone === 1 ? 'Secteur de grand intérêt biologique' : 'Grand ensemble naturel riche'">Type {{ z.ty_zone }}</span></td>
                                    <td class="text-end">{{ z.surface_ha ? fmtNb(z.surface_ha, 0) : '—' }}</td>
                                    <td class="text-end">{{ z.nb_especes_determinantes || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="patrimoine.natura.length" class="mb-3">
                    <h3 class="h6">Sites Natura 2000</h3>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Site</th><th>Type</th><th class="text-end">Habitats</th><th class="text-end">Espèces (directive)</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in patrimoine.natura" :key="s.sitecode">
                                    <td><Link class="text-reset text-decoration-none" :href="`/patrimoine/natura/${s.sitecode}`" :title="`Fiche Natura 2000 ${s.nom || s.sitecode}`">{{ s.nom || 'Sans nom publié' }}</Link> <span class="text-muted">({{ s.sitecode }})</span></td>
                                    <td><span class="badge text-bg-light" :title="s.type_site === 'ZPS' ? 'Zone de protection spéciale (directive Oiseaux)' : 'Zone spéciale de conservation (directive Habitats)'">{{ s.type_site === 'ZPS' ? 'ZPS' : 'ZSC' }}</span></td>
                                    <td class="text-end">{{ s.nb_habitats || '—' }}</td>
                                    <td class="text-end">{{ s.nb_especes || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="patrimoine.aires.length" class="mb-3">
                    <h3 class="h6">Aires protégées</h3>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Nom</th><th>Type</th><th class="text-end">Sur la commune (ha)</th><th>Document de gestion</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in patrimoine.aires" :key="a.id_mnhn">
                                    <td><a v-if="a.lien_fiche" :href="a.lien_fiche" target="_blank" rel="noopener">{{ a.nom }}</a><template v-else>{{ a.nom }}</template></td>
                                    <td>{{ a.type_espace }}</td>
                                    <td class="text-end">{{ a.surface_intersection_ha ? fmtNb(a.surface_intersection_ha, 0) : '—' }}</td>
                                    <td><span v-if="a.doc_gestion" class="badge text-bg-success">Oui</span><span v-else class="text-muted">—</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted">Les surfaces sur la commune sont des recouvrements calculés (approche décimétrique à l'échelle communale) : à considérer comme des ordres de grandeur.</p>
                </div>
            </template>

            <p><Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link></p>
        </div>
    </Layout>
</template>
