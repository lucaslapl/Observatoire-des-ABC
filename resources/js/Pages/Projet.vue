<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    projet: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const crumbs = computed(() => {
    const list = [];
    if (props.projet.region_slug) {
        list.push({ label: props.projet.region_label, url: `/region/${props.projet.region_slug}` });
    }
    list.push({ label: props.projet.nom });
    return list;
});

const statutClass = (s) =>
    ({ en_cours: 'text-bg-success', a_venir: 'text-bg-warning', termine: 'text-bg-primary', inconnu: 'text-bg-secondary' })[s] || 'text-bg-secondary';

const pageTitle = computed(() => props.projet.nom);
const pageDescription = computed(() => {
    const p = props.projet;
    return `${p.nom} (${p.statut_label})${p.structure_porteuse ? ' — ' + p.structure_porteuse : ''}${p.annee_debut ? ' — depuis ' + p.annee_debut : ''}. ${p.communes.length} commune(s) concernée(s), source ${p.source_label}.`;
});

const jsonLd = computed(() => {
    const base = site.value.url || '';
    const p = props.projet;
    const items = [{ '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' }];
    if (p.region_slug) {
        items.push({ '@type': 'ListItem', position: 2, name: p.region_label, item: `${base}/region/${p.region_slug}` });
    }
    items.push({ '@type': 'ListItem', position: items.length + 1, name: p.nom });

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: items,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Project',
            name: p.nom,
            url: `${base}/abc/${p.slug}`,
            description: `${p.nom} — Atlas de la Biodiversité Communale (${p.statut_label})`,
            identifier: p.id,
            ...(p.annee_debut ? { startDate: String(p.annee_debut) } : {}),
        },
    ];
});

const annee = (p) => (p.annee_debut ? `${p.annee_debut}${p.annee_fin ? '–' + p.annee_fin : ''}` : '—');
</script>

<template>
    <Layout>
        <Seo
            :title="pageTitle"
            :description="pageDescription"
            :jsonLd="jsonLd"
        />
        <div class="container py-4">
            <Breadcrumbs :items="crumbs" />
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <h1 class="h3 mb-0">{{ projet.nom }}</h1>
                <span class="badge" :class="statutClass(projet.statut)">{{ projet.statut_label }}</span>
                <span class="badge text-bg-light">{{ projet.source_label }}</span>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <table class="table table-sm mb-0 align-middle">
                        <tbody>
                            <tr v-if="projet.structure_porteuse">
                                <th scope="row" class="text-muted" style="width:200px">Structure porteuse</th>
                                <td>{{ projet.structure_porteuse }}<template v-if="projet.type_de_structure_porteuse"> <small class="text-muted">({{ projet.type_de_structure_porteuse }})</small></template></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted">Années</th>
                                <td>{{ annee(projet) }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted">Communes concernées</th>
                                <td>{{ projet.communes.length }}<span v-if="projet.communes_anomalies"> (+ {{ projet.communes_anomalies }} écartée(s))</span></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-muted">Source</th>
                                <td>{{ projet.source_label }}</td>
                            </tr>
                            <tr v-if="projet.url_page">
                                <th scope="row" class="text-muted">Lien source</th>
                                <td><a :href="projet.url_page" target="_blank" rel="noopener">Page du registre <span aria-hidden="true">↗</span></a></td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="projet.description" class="mt-3">
                        <h2 class="h6">Description</h2>
                        <p style="white-space: pre-wrap;" class="mb-0">{{ projet.description }}</p>
                    </div>

                    <div v-if="projet.verification" class="mt-3 small">
                        <span :class="projet.verification.etat === 'douteux' || projet.verification.etat === 'introuvable' ? 'text-danger' : 'text-success'" class="fw-semibold">{{ projet.verification.label }}</span>
                        <template v-if="projet.verification.note">&nbsp;— {{ projet.verification.note }}</template>
                        <template v-if="projet.verification.lien">&nbsp;(<a :href="projet.verification.lien" target="_blank" rel="noopener">lien</a>)</template>
                    </div>
                    <div v-if="projet.donnees_2022" class="alert alert-warning small mt-3 mb-0">
                        Statut figé à l'instantané des archives 2022 — site officiel hors ligne, statut possiblement obsolète.
                    </div>
                    <div v-else-if="projet.estime_termine" class="alert alert-info small mt-3 mb-0">
                        Projet débuté en {{ projet.annee_debut }} (il y a plus de 5 ans) : terminé selon l'estimation, statut officiel inconnu.
                    </div>
                    <div v-else-if="projet.potentiellement_termine" class="alert alert-light small mt-3 mb-0">
                        Début {{ projet.annee_debut }}, durée d'un ABC ≈ 3 ans : potentiellement terminé.
                    </div>
                    <div v-else-if="projet.potentiellement_en_cours" class="alert alert-light small mt-3 mb-0">
                        Début annoncé {{ projet.annee_debut }}, toujours « va débuter » : potentiellement en cours.
                    </div>
                </div>
            </div>

            <h2 class="h5">Communes du périmètre</h2>
            <p v-if="projet.communes.length === 0" class="text-muted small">Aucune commune répertoriée.</p>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <Link
                    v-for="c in projet.communes"
                    :key="c.code"
                    :href="`/commune/${c.code}`"
                    class="list-group-item border rounded-3 px-3 py-2 small text-decoration-none text-reset"
                >
                    {{ c.libelle }}
                    <template v-if="c.departement_label"><small class="text-muted"> · {{ c.departement_label }}</small></template>
                </Link>
            </div>

            <p>
                <Link class="btn btn-outline-success btn-sm" href="/">← Revenir à la carte</Link>
            </p>
        </div>
    </Layout>
</template>
