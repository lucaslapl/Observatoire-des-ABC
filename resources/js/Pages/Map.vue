<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import MapExplorer from '../Components/MapExplorer.vue';
import ConsentBanner from '../Components/ConsentBanner.vue';
import Footer from '../Components/Footer.vue';
import InfoBanner from '../Components/InfoBanner.vue';

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
    journal: {
        type: Object,
        default: () => ({ items: [], page: 1, last_page: 1, total: 0, has_prev: false, has_next: false }),
    },
    dernieresActualites: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const site = computed(() => page.props.site || {});
const authUser = computed(() => page.props.auth?.user || null);
const fmtSourceDate = (d) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
const fmtActuDate = (d) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
const fmtJournalDate = (d) => {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    return `${dt.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })} à ${dt.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
};

const seoJsonLd = computed(() => {
    const meta = props.meta || {};
    const base = site.value.url || '';
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
            url: `${base}/`,
            logo: { '@type': 'ImageObject', url: `${base}/favicon.png` },
            description:
                'Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des projets financés, carte interactive et vérifications.',
        },
        {
            '@context': 'https://schema.org',
            '@type': 'Dataset',
            name: 'Observatoire des Atlas de la Biodiversité Communale (ABC)',
            description: `${count} projets ABC suivis en France Métropolitaine et Outre-mer : statut, communes, porteur, périodes et sources (Registre OFB, Fonds vert, archives 2022).`,
            url: `${base}/`,
            license: 'https://www.etalab.gouv.fr/licence-ouverte-open-licence/',
            publisher: { '@id': '#organization' },
            distribution: [
                {
                    '@type': 'DataDownload',
                    contentUrl: `${base}/api/abc.geojson`,
                    encodingFormat: 'application/geo+json',
                    name: 'Observatoire des ABC — export GeoJSON',
                },
            ],
            ...(datePublished ? { dateModified: datePublished } : {}),
            ...(count ? { variableMeasured: [{ name: 'nombre de projets ABC suivis', value: count, unitText: 'projets' }] } : {}),
        },
    ];
});
</script>

<template>
    <div class="d-flex flex-column min-vh-100">
        <Seo
            description="Carte des Atlas de la Biodiversité Communale (ABC) en France : suivi des projets financés (Registre OFB, Fonds vert), statuts, communes et porteurs — départements et régions."
            :jsonLd="seoJsonLd"
        />
        <InfoBanner />
        <MapExplorer :meta="meta" :is-admin="isAdmin" :compact-legend="true" />

        <section class="home-content flex-grow-1">
            <div class="container py-5">
                <div class="row">
                    <div class="col-lg-8">
                        <h1>Observatoire des ABC — Atlas de la Biodiversité Communale</h1>
                        <p>
                            L'Observatoire des ABC est le suivi national des <strong>Atlas de la Biodiversité Communale</strong>,
                            démarches financées par l'État (Registre OFB et Fonds vert) pour connaître la biodiversité
                            d'une commune et la faire découvrir à ses habitants. La carte ci-dessus recense
                            <strong>{{ meta?.countProjets ?? 0 }} projets</strong> sur
                            <strong>{{ meta?.countCommunes ?? 'de nombreuses' }} communes</strong>,
                            avec leur statut (en cours, va débuter, terminé), leur porteur et leurs périodes.
                        </p>
                        <p>
                            Chaque projet est documenté depuis les sources publiques (data.gouv.fr) puis vérifié manuellement
                            quand nécessaire : les données publiées ici réutilisent l'attribution de
                            <a :href="site?.sources?.dataGouv || 'https://data.gouv.fr'">leurs producteurs</a>.
                        </p>
                        <div class="home-stats">
                            <div class="stat"><span class="n">{{ meta?.countProjets ?? 0 }}</span><span class="l">projets suivis</span></div>
                            <div class="stat"><span class="n"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ meta?.countVerifies ?? 0 }}</span><span class="l">projets vérifiés manuellement</span></div>
                            <div class="stat"><span class="n">{{ meta?.countEstimes ?? 0 }}</span><span class="l">reclassés « terminé » (&gt; 5 ans)</span></div>
                            <div class="stat"><span class="n">{{ meta?.countAnomalies ?? 0 }}</span><span class="l">communes incohérentes écartées</span></div>
                        </div>
                        <p v-if="meta?.countNonLocalises" class="small text-muted mt-2 mb-0">
                            {{ meta.countNonLocalises }} projet{{ meta.countNonLocalises > 1 ? 's' : '' }}
                            {{ meta.countNonLocalises > 1 ? 'figurent' : 'figure' }} dans les statistiques mais pas
                            sur la carte : leurs communes n'ont pas pu être géolocalisées.
                        </p>
                        <p class="mt-3 mb-0">
                            <a class="btn btn-outline-success btn-sm" href="/carte">Accéder à la carte complète</a>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/abc">Tous les ABC — liste filtrable</Link>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/a-propos">En savoir plus</Link>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/actualites">Suivre l'actualité</Link>
                            <Link class="btn btn-outline-success btn-sm ms-2" href="/verify">Page de vérification</Link>
                        </p>
                        <p v-if="!authUser" class="contribute-hint">
                            <i class="bi bi-person-plus" aria-hidden="true"></i>
                            Un ABC manque sur la carte ou une donnée vous semble erronée ?
                            <Link href="/register?raison=contribution">Créez un compte</Link>
                            pour proposer vos corrections et suivre leurs validations.
                        </p>
                    </div>
                    <div class="col-lg-4">
                        <div class="contribute-card">
                            <h2><i class="bi bi-pencil-square" aria-hidden="true"></i> Contribuer à l'Observatoire</h2>
                            <p>
                                L'Observatoire s'appuie sur des données publiques, mais votre connaissance du terrain
                                compte tout autant : corrigez un statut, précisez une période, signalez un projet manquant.
                            </p>
                            <template v-if="!authUser">
                                <Link class="btn btn-success btn-sm" href="/register?raison=contribution">Créer un compte</Link>
                                <Link class="btn btn-outline-success btn-sm ms-1" href="/login">Se connecter</Link>
                                <div class="mt-1">
                                    <Link class="alt" href="/login">Déjà inscrit ? Connectez-vous pour retrouver vos contributions.</Link>
                                </div>
                            </template>
                            <template v-else>
                                <Link class="btn btn-success btn-sm" href="/profil">Gérer mes contributions</Link>
                            </template>
                        </div>
                    </div>
                </div>

                <h2 class="home-band-title">Suivre l'Observatoire</h2>
                <div class="row g-4">
                    <div v-if="dernieresActualites.length" class="col-lg-5">
                        <section class="home-actus h-100" aria-label="Dernières actualités">
                            <h3 class="h6"><i class="bi bi-newspaper me-1" aria-hidden="true"></i> Dernières actualités</h3>
                            <ul class="list-unstyled actus-list mb-2">
                                <li v-for="a in dernieresActualites" :key="a.id">
                                    <span class="date">{{ fmtActuDate(a.date_publication) }}</span>
                                    <Link class="titre" :href="`/actualites/${a.slug}`">{{ a.titre }}</Link>
                                    <p class="extrait mb-0">{{ a.extrait }}</p>
                                </li>
                            </ul>
                            <Link class="actus-tout" href="/actualites">
                                Toutes les actualités<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                            </Link>
                        </section>
                    </div>
                    <div :class="dernieresActualites.length ? 'col-lg-7' : 'col-12'">
                        <section class="home-changelog h-100" aria-label="Dernières mises à jour de la carte">
                            <h3 class="h6"><i class="bi bi-clock-history me-1" aria-hidden="true"></i> Dernières mises à jour de la carte</h3>
                            <p class="small text-muted">
                                Corrections et vérifications appliquées aux données publiées : dates validées,
                                statuts corrigés, doublons fusionnés…
                            </p>
                            <ul v-if="journal.items.length" class="list-unstyled changelog-list mb-0">
                                <li v-for="(e, i) in journal.items" :key="`${e.date}-${i}`">
                                    <span class="date">{{ fmtJournalDate(e.date) }}</span>
                                    <span class="label">{{ e.label }}</span>
                                    <span v-if="e.detail" class="detail">({{ e.detail }})</span>
                                    <template v-if="e.projet_nom">
                                        —
                                        <Link v-if="e.projet_slug" class="projet" :href="`/abc/${e.projet_slug}`">{{ e.projet_nom }}</Link>
                                        <span v-else class="projet">{{ e.projet_nom }}</span>
                                    </template>
                                    <span v-if="e.commune" class="detail">({{ e.commune }})</span>
                                    <span v-if="e.auteur" class="auteur">· par {{ e.auteur }}</span>
                                </li>
                            </ul>
                            <p v-else class="small text-muted mb-0">Aucune correction enregistrée pour le moment.</p>
                            <nav v-if="journal.last_page > 1" class="mt-2" aria-label="Pagination des mises à jour">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item" :class="{ disabled: !journal.has_prev }">
                                        <Link
                                            v-if="journal.has_prev"
                                            class="page-link"
                                            :href="`/?journal_page=${journal.page - 1}`"
                                            :only="['journal']"
                                            preserve-scroll
                                        >Précédent</Link>
                                        <span v-else class="page-link">Précédent</span>
                                    </li>
                                    <li class="page-item disabled" aria-current="page">
                                        <span class="page-link">{{ journal.page }} / {{ journal.last_page }}</span>
                                    </li>
                                    <li class="page-item" :class="{ disabled: !journal.has_next }">
                                        <Link
                                            v-if="journal.has_next"
                                            class="page-link"
                                            :href="`/?journal_page=${journal.page + 1}`"
                                            :only="['journal']"
                                            preserve-scroll
                                        >Suivant</Link>
                                        <span v-else class="page-link">Suivant</span>
                                    </li>
                                </ul>
                            </nav>
                        </section>
                    </div>
                </div>

                <h2 class="home-band-title">Explorer par territoire</h2>
                <div class="row g-4">
                    <div class="col-lg-4">
                        <h3 class="h6">Par région</h3>
                        <ul class="list-unstyled home-links home-links-regions">
                            <li v-for="r in index?.regions" :key="r.slug">
                                <Link :href="`/region/${r.slug}`">{{ r.label }} <small>({{ r.n }})</small></Link>
                            </li>
                        </ul>
                    </div>
                    <div class="col-lg-8">
                        <h3 class="h6">Par département</h3>
                        <ul class="list-unstyled home-links home-links-departements">
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

        <Footer />

        <ConsentBanner />
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
.home-links { font-size: .9rem; }
.home-links li { break-inside: avoid; margin-bottom: .25rem; }
.home-links a { color: #14532d; text-decoration: none; }
.home-links a:hover { text-decoration: underline; }
.home-links small { color: #94a3b8; }
.home-links-regions { column-count: 2; column-gap: 1rem; }
.home-links-departements { column-count: 3; column-gap: 1.25rem; }
@media (max-width: 991.98px) {
    .home-links-departements { column-count: 2; }
}
.home-band-title {
    color: #14532d; font-weight: 700; font-size: 1.15rem;
    margin: 2.5rem 0 1rem; padding-bottom: .35rem; border-bottom: 1px solid #e2e8f0;
}
.home-actus {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.home-actus h3 { color: #14532d; font-weight: 700; }
.actus-list li { padding: .6rem 0; border-bottom: 1px dashed #e2e8f0; }
.actus-list li:last-child { border-bottom: none; }
.actus-list .date {
    display: block; font-size: .75rem; color: #689f38;
    font-variant-numeric: tabular-nums; margin-bottom: .15rem;
}
.actus-list .titre { display: block; font-size: .95rem; font-weight: 600; color: #14532d; text-decoration: none; }
.actus-list .titre:hover { text-decoration: underline; }
.actus-list .extrait {
    font-size: .82rem; color: #64748b; margin-top: .2rem;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.actus-tout { font-size: .85rem; font-weight: 600; color: #166534; text-decoration: none; }
.actus-tout:hover { text-decoration: underline; }
.home-sources { padding: 1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.contribute-card {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 1rem 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.contribute-card h2 { font-size: 1rem; color: #14532d; margin-bottom: .4rem; }
.contribute-card p { font-size: .85rem; color: #475569; margin-bottom: .75rem; }
.contribute-card a.alt { font-size: .8rem; color: #166534; text-decoration: none; }
.contribute-card a.alt:hover { text-decoration: underline; }
.contribute-hint { font-size: .85rem; color: #64748b; margin-top: 1rem; margin-bottom: 0; }
.contribute-hint a { color: #14532d; font-weight: 600; }
.home-changelog {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.home-changelog h3 { color: #14532d; }
.home-changelog > p { margin-bottom: .75rem; }
.changelog-list li { font-size: .9rem; color: #334155; padding: .35rem 0; border-bottom: 1px dashed #e2e8f0; }
.changelog-list li:last-child { border-bottom: none; }
.changelog-list .date { color: #94a3b8; font-variant-numeric: tabular-nums; margin-right: .5rem; }
.changelog-list .detail { color: #64748b; margin-left: .25rem; }
.changelog-list .auteur { color: #94a3b8; margin-left: .25rem; }
.changelog-list .projet { color: #14532d; font-weight: 600; }
</style>