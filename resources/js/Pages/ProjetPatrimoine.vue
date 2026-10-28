<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    projet: { type: Object, required: true },
    patrimoine: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const p = computed(() => props.projet);
const pat = computed(() => props.patrimoine);

/* Nombre au format français (espace fine pour les milliers, virgule décimale). */
const fmtNb = (v, decimales = 0) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: decimales });

const crumbs = computed(() => {
    const list = [];
    if (p.value.region_slug) {
        list.push({ label: p.value.region_label, url: `/region/${p.value.region_slug}` });
    }
    list.push({ label: p.value.nom, url: `/abc/${p.value.slug}` });
    list.push({ label: 'Patrimoine écologique' });
    return list;
});

const pageTitle = computed(() => `Patrimoine écologique — ${p.value.nom}`);
const pageDescription = computed(() =>
    `ZNIEFF, sites Natura 2000 et aires protégées (inventaires nationaux INPN) touchant les ${pat.value.nb_communes} commune(s) du périmètre de l'ABC ${p.value.nom}.`
);

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    if (p.value.region_slug) {
        items.push({ '@type': 'ListItem', position: 2, name: p.value.region_label, item: `${base}/region/${p.value.region_slug}` });
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: p.value.nom, item: `${base}/abc/${p.value.slug}` });
    items.push({ '@type': 'ListItem', position: items.length + 1, name: 'Patrimoine écologique', item: `${base}/abc/${p.value.slug}/patrimoine` });

    return [{
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: items,
    }];
});

const versionsTexte = computed(() => {
    const v = pat.value.versions || {};
    const morceaux = [];
    if (v.znieff) morceaux.push(`ZNIEFF : ${v.znieff.toLowerCase()}`);
    if (v.natura) morceaux.push(`Natura 2000 : ${v.natura.toLowerCase()}`);
    if (v.aires) morceaux.push(`aires protégées : ${v.aires.toLowerCase()}`);
    return morceaux.join(', ');
});

/* Sections paginées côté serveur : la navigation d'une section conserve
   la page courante des deux autres dans l'URL (?znieff=&natura=&aires=,
   un paramètre absent vaut page 1). */
const SECTIONS = ['znieff', 'natura', 'aires'];

const pageUrl = (section, n) => {
    const params = new URLSearchParams();
    if (n > 1) params.set(section, String(n));
    for (const autre of SECTIONS) {
        if (autre !== section && pat.value[autre].page > 1) params.set(autre, String(pat.value[autre].page));
    }
    const q = params.toString();
    return `/abc/${p.value.slug}/patrimoine${q ? '?' + q : ''}`;
};

const libelleTotal = (section, singulier, pluriel) => {
    const total = pat.value[section].total;
    return `${fmtNb(total)} ${total > 1 ? pluriel : singulier}`;
};
</script>

<template>
    <Layout>
        <Seo :title="pageTitle" :description="pageDescription" :jsonLd="jsonLd" />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <h1 class="h3 mb-2">Patrimoine écologique</h1>
            <p class="text-muted">
                Inventaires nationaux du patrimoine naturel (<a href="https://inpn.mnhn.fr" target="_blank" rel="noopener">INPN</a>)
                touchant les {{ pat.nb_communes }} commune(s) du périmètre de l'ABC
                <Link class="text-decoration-none" :href="`/abc/${projet.slug}`">{{ projet.nom }}</Link>.
                <template v-if="versionsTexte">
                    Données factuelles telles que publiées dans les référentiels ({{ versionsTexte }}).
                </template>
                <template v-if="projet.nb_communes_ecartees">
                    Les {{ projet.nb_communes_ecartees }} commune(s) marquée(s) « à vérifier » (écart géographique) ne sont pas comptées ici.
                </template>
                Méthode détaillée : <Link class="text-decoration-none" href="/methodologie">méthodologie</Link>.
            </p>

            <template v-if="pat.present">
                <section v-if="pat.znieff.total" class="mb-4" aria-label="ZNIEFF">
                    <h2 class="h5">ZNIEFF <small class="text-muted fs-6">{{ libelleTotal('znieff', 'zone', 'zones') }}</small></h2>
                    <p class="small text-muted">
                        Zones naturelles d'intérêt écologique, faunistique et floristique recensées sur au moins une commune du périmètre.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Nom</th><th>Type</th><th class="text-end">Surface (ha)</th><th class="text-end">Espèces déterminantes</th><th>Communes du périmètre</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="z in pat.znieff.items" :key="z.id_national">
                                    <td>
                                        <Link class="text-reset text-decoration-none" :href="`/patrimoine/znieff/${z.id_national}`" :title="`Fiche ZNIEFF ${z.nom}`">{{ z.nom }}</Link>
                                        <span class="text-muted"> ({{ z.id_national }})</span>
                                    </td>
                                    <td><span class="badge text-bg-light" :title="z.ty_zone === 1 ? 'Secteur de grand intérêt biologique' : 'Grand ensemble naturel riche'">Type {{ z.ty_zone }}</span></td>
                                    <td class="text-end">{{ z.surface_ha ? fmtNb(z.surface_ha, 0) : '—' }}</td>
                                    <td class="text-end">{{ z.nb_especes_determinantes || '—' }}</td>
                                    <td>
                                        <template v-for="(c, i) in z.communes" :key="c.code">
                                            <template v-if="i > 0">, </template>
                                            <Link class="text-decoration-none" :href="`/commune/${c.code}`" :title="`Page commune ${c.libelle}`">{{ c.libelle }}</Link>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav v-if="pat.znieff.pages > 1" aria-label="Pagination ZNIEFF">
                        <ul class="pagination pagination-sm">
                            <li class="page-item" :class="{ disabled: pat.znieff.page <= 1 }">
                                <Link class="page-link" :href="pageUrl('znieff', pat.znieff.page - 1)" :aria-disabled="pat.znieff.page <= 1">Précédent</Link>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page {{ pat.znieff.page }} / {{ pat.znieff.pages }}</span></li>
                            <li class="page-item" :class="{ disabled: pat.znieff.page >= pat.znieff.pages }">
                                <Link class="page-link" :href="pageUrl('znieff', pat.znieff.page + 1)">Suivant</Link>
                            </li>
                        </ul>
                    </nav>
                </section>

                <section v-if="pat.natura.total" class="mb-4" aria-label="Sites Natura 2000">
                    <h2 class="h5">Sites Natura 2000 <small class="text-muted fs-6">{{ libelleTotal('natura', 'site', 'sites') }}</small></h2>
                    <p class="small text-muted">
                        Sites du réseau européen Natura 2000 (directive Habitats ou Oiseaux) touchant au moins une commune du périmètre.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Site</th><th>Type</th><th class="text-end">Habitats</th><th class="text-end">Espèces (directive)</th><th>Communes du périmètre</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in pat.natura.items" :key="s.sitecode">
                                    <td>
                                        <Link class="text-reset text-decoration-none" :href="`/patrimoine/natura/${s.sitecode}`" :title="`Fiche Natura 2000 ${s.nom || s.sitecode}`">{{ s.nom || 'Site ' + s.sitecode }}</Link>
                                        <span class="text-muted"> ({{ s.sitecode }})</span>
                                    </td>
                                    <td><span class="badge text-bg-light" :title="s.type_site === 'ZPS' ? 'Zone de protection spéciale (directive Oiseaux)' : 'Zone spéciale de conservation (directive Habitats)'">{{ s.type_site === 'ZPS' ? 'ZPS' : 'ZSC' }}</span></td>
                                    <td class="text-end">{{ s.nb_habitats || '—' }}</td>
                                    <td class="text-end">{{ s.nb_especes || '—' }}</td>
                                    <td>
                                        <template v-for="(c, i) in s.communes" :key="c.code">
                                            <template v-if="i > 0">, </template>
                                            <Link class="text-decoration-none" :href="`/commune/${c.code}`" :title="`Page commune ${c.libelle}`">{{ c.libelle }}</Link>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav v-if="pat.natura.pages > 1" aria-label="Pagination sites Natura 2000">
                        <ul class="pagination pagination-sm">
                            <li class="page-item" :class="{ disabled: pat.natura.page <= 1 }">
                                <Link class="page-link" :href="pageUrl('natura', pat.natura.page - 1)" :aria-disabled="pat.natura.page <= 1">Précédent</Link>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page {{ pat.natura.page }} / {{ pat.natura.pages }}</span></li>
                            <li class="page-item" :class="{ disabled: pat.natura.page >= pat.natura.pages }">
                                <Link class="page-link" :href="pageUrl('natura', pat.natura.page + 1)">Suivant</Link>
                            </li>
                        </ul>
                    </nav>
                </section>

                <section v-if="pat.aires.total" class="mb-4" aria-label="Aires protégées">
                    <h2 class="h5">Aires protégées <small class="text-muted fs-6">{{ libelleTotal('aires', 'aire', 'aires') }}</small></h2>
                    <p class="small text-muted">
                        Aires protégées (APA-INPN) recouvrant au moins une commune du périmètre.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle small">
                            <thead>
                                <tr><th>Nom</th><th>Type</th><th class="text-end">Sur le périmètre (ha)</th><th>Document de gestion</th><th>Communes du périmètre</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in pat.aires.items" :key="a.id_mnhn">
                                    <td>
                                        <a v-if="a.lien_fiche" :href="a.lien_fiche" target="_blank" rel="noopener">{{ a.nom }}</a>
                                        <template v-else>{{ a.nom }}</template>
                                    </td>
                                    <td>{{ a.type_espace }}</td>
                                    <td class="text-end">{{ a.surface_perimetre_ha ? fmtNb(a.surface_perimetre_ha, 0) : '—' }}</td>
                                    <td><span v-if="a.doc_gestion" class="badge text-bg-success">Oui</span><span v-else class="text-muted">—</span></td>
                                    <td>
                                        <template v-for="(c, i) in a.communes" :key="c.code">
                                            <template v-if="i > 0">, </template>
                                            <Link class="text-decoration-none" :href="`/commune/${c.code}`" :title="`Page commune ${c.libelle}`">{{ c.libelle }}</Link>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted">
                        Les surfaces sur le périmètre sont des recouvrements calculés (approche décimétrique à l'échelle communale) :
                        à considérer comme des ordres de grandeur.
                    </p>
                    <nav v-if="pat.aires.pages > 1" aria-label="Pagination aires protégées">
                        <ul class="pagination pagination-sm">
                            <li class="page-item" :class="{ disabled: pat.aires.page <= 1 }">
                                <Link class="page-link" :href="pageUrl('aires', pat.aires.page - 1)" :aria-disabled="pat.aires.page <= 1">Précédent</Link>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page {{ pat.aires.page }} / {{ pat.aires.pages }}</span></li>
                            <li class="page-item" :class="{ disabled: pat.aires.page >= pat.aires.pages }">
                                <Link class="page-link" :href="pageUrl('aires', pat.aires.page + 1)">Suivant</Link>
                            </li>
                        </ul>
                    </nav>
                </section>

                <p class="small">
                    <Link class="text-decoration-none" href="/patrimoine">Parcourir tout le patrimoine écologique des communes ABC</Link>
                    <span aria-hidden="true"> →</span>
                </p>
            </template>
            <div v-else class="alert alert-light">
                Aucune ZNIEFF, site Natura 2000 ni aire protégée des référentiels INPN ne touche les communes de ce périmètre.
            </div>

            <p class="mt-4 d-flex flex-wrap gap-2">
                <Link class="btn btn-outline-success btn-sm" :href="`/abc/${projet.slug}`">← Fiche de l'ABC</Link>
                <Link class="btn btn-outline-success btn-sm" href="/">Revenir à la carte</Link>
            </p>
        </div>
    </Layout>
</template>
