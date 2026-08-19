<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import MapExplorer from '../Components/MapExplorer.vue';

const props = defineProps({
    meta: {
        type: Object,
        default: null,
    },
    index: {
        type: Object,
        default: () => ({ regions: [], departements: [] }),
    },
    isAdmin: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const site = computed(() => page.props.site || {});
const fmtSourceDate = (d) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const seoJsonLd = computed(() => {
    const meta = props.meta || {};
    const sources = meta.sources || {};
    const dates = Object.values(sources).filter(Boolean).sort();
    const datePublished = dates.length ? dates[dates.length - 1] : undefined;
    const count = meta.countProjets || 0;

    return [
        {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            '@id': '#organization',
            name: 'Observatoire des ABC',
            url: undefined,
            description:
                'Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des projets financés, carte interactive et vérifications.',
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Dataset',
            name: 'Observatoire des Atlas de la Biodiversité Communale (ABC)',
            description: `${count} projets ABC suivis en France Métropolitaine et Outre-mer : statut, communes, porteur, périodes et sources (Registre OFB, Fonds vert, archives 2022).`,
            url: undefined,
            license: 'https://www.etalab.gouv.fr/licence-ouverte-open-licence/',
            publisher: { '@id': '#organization' },
            ...(datePublished ? { dateModified: datePublished } : {}),
            ...(count ? { variableMeasured: [{ name: 'nombre de projets ABC suivis', value: count, unitText: 'projets' }] } : {}),
        },
    ];
});
</script>

<template>
    <div class="d-flex flex-column">
        <Seo
            description="Carte des Atlas de la Biodiversité Communale (ABC) en France : suivi des projets financés (Registre OFB, Fonds vert), statuts, communes et porteurs — départements et régions."
            :jsonLd="seoJsonLd"
        />
        <MapExplorer :meta="meta" :is-admin="isAdmin" :compact-legend="true" />

        <section class="home-content">
            <div class="container py-5">
                <div class="row">
                    <div class="col-lg-8">
                        <h1>Observatoire des ABC — Atlas de la Biodiversité Communale</h1>
                        <p>
                            L'Observatoire des ABC est le suivi national des <strong>Atlas de la Biodiversité Communale</strong>,
                            démarches financées par l'État (Registre OFB et Fonds vert) pour connaître la biodiversité
                            d'une commune et la faire découvrir à ses habitants. La carte ci-dessus recense
                            <strong>{{ meta?.countProjets ?? 0 }} projets</strong> sur
                            <strong>{{ meta?.countCommunes ?? 'plus de 5 400' }} communes</strong>,
                            avec leur statut (en cours, va débuter, terminé), leur porteur et leurs périodes.
                        </p>
                        <p>
                            Chaque projet est documenté depuis les sources publiques (data.gouv.fr) puis vérifié manuellement
                            quand nécessaire : les données publiées ici réutilisent l'attribution de
                            <a :href="site?.sources?.dataGouv || 'https://data.gouv.fr'">leurs producteurs</a>.
                        </p>
                        <div class="home-stats">
                            <div class="stat"><span class="n">{{ meta?.countProjets ?? 0 }}</span><span class="l">projets suivis</span></div>
                            <div class="stat"><span class="n">✓ {{ meta?.countVerifies ?? 0 }}</span><span class="l">projets vérifiés manuellement</span></div>
                            <div class="stat"><span class="n">{{ meta?.countEstimes ?? 0 }}</span><span class="l">reclassés « terminé » (&gt; 5 ans)</span></div>
                            <div class="stat"><span class="n">{{ meta?.countAnomalies ?? 0 }}</span><span class="l">communes incohérentes écartées</span></div>
                        </div>
                        <p class="mt-3 mb-0">
                            <a class="btn btn-outline-success btn-sm" href="/carte">Accéder à la carte complète</a>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/actualites">Suivre l'actualité</Link>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/verify">Page de vérification</Link>
                        </p>
                    </div>
                    <div class="col-lg-4">
                        <h2 class="h5">Explorer par région</h2>
                        <ul class="list-unstyled home-links">
                            <li v-for="r in index?.regions" :key="r.slug">
                                <Link :href="`/region/${r.slug}`">{{ r.label }} <small>({{ r.n }})</small></Link>
                            </li>
                        </ul>
                        <h2 class="h5 mt-4">Explorer par département</h2>
                        <ul class="list-unstyled home-links">
                            <li v-for="d in index?.departements" :key="d.code">
                                <Link :href="`/departement/${d.code}`">{{ d.label }} <small>({{ d.n }})</small></Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="container">
                <div class="home-sources mb-2">
                    <h2 class="h6">Les données</h2>
                    <p class="small text-muted mb-0">{{ site?.licenseNote }}</p>
                    <ul class="small text-muted mb-0">
                        <li>Registre OFB — Atlas de la Biodiversité Communale, mis à jour le {{ fmtSourceDate(meta?.sources?.['data.gouv']) }}</li>
                        <li>Fonds vert (P113) — liste des projets subventionnés</li>
                        <li>Archives 2022 du registre national ABC</li>
                    </ul>
                </div>
            </div>
        </section>

        <footer class="home-footer py-3">
            <div class="container small text-center text-muted">
                <div>Observatoire des ABC — données réutilisées en lisant <a href="https://data.gouv.fr" rel="noopener">data.gouv.fr</a>. Projet ouvert.</div>
                <div class="my-1">
                    Développé par <a href="https://lucaslaplanche.fr" target="_blank" rel="noopener">Lucas LAPLANCHE</a> ·
                    Code source sur <a href="https://github.com/lucaslapl/Observatoire-des-ABC" target="_blank" rel="noopener">GitHub</a>
                </div>
                <div>
                    <Link href="/carte">Carte</Link>&nbsp;·
                    <Link href="/actualites">Actualités</Link>&nbsp;·
                    <Link href="/mentions-legales">Mentions légales</Link>&nbsp;·
                    <Link href="/confidentialite">Confidentialité</Link>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.home-content { background: #f8faf9; border-top: 1px solid #e2e8f0; }
.home-content h1 { font-size: 1.7rem; font-weight: 700; color: #14532d; margin-bottom: 1rem; }
.home-content h2 { color: #14532d; }
.home-stats { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1.25rem; }
.home-stats .stat {
    flex: 1 1 130px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.home-stats .stat .n { display: block; font-size: 1.35rem; font-weight: 700; color: #14532d; }
.home-stats .stat .l { display: block; font-size: .8rem; color: #64748b; }
.home-links { column-count: 2; column-gap: 1rem; font-size: .9rem; }
.home-links li { break-inside: avoid; margin-bottom: .25rem; }
.home-links a { color: #14532d; text-decoration: none; }
.home-links a:hover { text-decoration: underline; }
.home-links small { color: #94a3b8; }
.home-sources { padding: 1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.home-footer { background: #14532d; color: #e8f5e9; }
.home-footer a { color: #fff; }
</style>