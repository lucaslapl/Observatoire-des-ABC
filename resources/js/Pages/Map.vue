<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Seo from '../Components/Seo.vue';
import MapExplorer from '../Components/MapExplorer.vue';
import ConsentBanner from '../Components/ConsentBanner.vue';
import Footer from '../Components/Footer.vue';
import InfoBanner from '../Components/InfoBanner.vue';
import { COLOR } from '../map/constants';

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

// Repères du bandeau d'accueil : comptes par statut, démarches actives,
// date de la dernière consolidation des sources.
const fmtNb = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0);
const statuts = computed(() => {
    const rows = props.meta?.stats || [];
    const get = (statut) => rows.find((r) => r.statut === statut)?.n ?? 0;
    const total = rows.reduce((sum, r) => sum + (r.n || 0), 0);
    return {
        aVenir: get('a_venir'),
        enCours: get('en_cours'),
        termines: get('termine'),
        actives: get('a_venir') + get('en_cours'),
        total,
    };
});
const pct = (n) => (statuts.value.total ? ((n / statuts.value.total) * 100).toFixed(1) : '0');
const derniereConsolidation = computed(() => {
    const dates = Object.values(props.meta?.sources || {}).filter(Boolean).sort();
    return dates.length
        ? new Date(`${dates[dates.length - 1]}T12:00:00`).toLocaleDateString('fr-FR')
        : '—';
});

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
                    <div class="col-lg-7 d-flex flex-column">
                        <div class="row align-items-center g-4 mb-3">
                            <div class="col-lg-7">
                                <h1 class="mb-0">L'Observatoire des ABC</h1>
                                <p class="home-subtitle">Le recensement public et cartographié des Atlas de la Biodiversité Communale</p>
                            </div>
                            <div class="col-lg-5">
                                <div class="home-bignum">
                                    <span class="n d-block">{{ fmtNb(meta?.countCommunes) }}</span>
                                    <span class="l d-block mt-2">communes couvertes par une démarche ABC</span>
                                    <span class="s d-block mt-1">environ une commune française sur sept, métropole et outre-mer compris</span>
                                </div>
                            </div>
                        </div>
                        <p>
                            Un <strong>Atlas de la biodiversité communale (ABC)</strong> est un inventaire de la
                            faune et de la flore d'une commune, mené avec ses habitants et ses associations.
                            Ces démarches sont soutenues par l'État (registre OFB, Fonds vert) et se
                            concluent par un atlas publié.
                        </p>
                        <p>
                            <strong>L'Observatoire des ABC</strong> est un projet indépendant. Il rassemble les registres
                            publics des ABC (<a :href="site?.sources?.dataGouv || 'https://data.gouv.fr'">data.gouv.fr</a>,
                            Fonds vert) et suit chaque démarche : où elle se trouve, où elle en est, qui la
                            porte, sur quelle période. Les données sont collectées automatiquement, puis
                            vérifiées et corrigées à la main quand nécessaire.
                        </p>
                        <p>
                            Le mouvement se renouvelle : une centaine de nouvelles campagnes démarrent chaque
                            année, portées par des communes qui s'engagent pour la première fois ou qui
                            enchaînent après un premier atlas terminé.
                        </p>
                        <div class="home-cta">
                            <a class="btn btn-success btn-sm" href="/carte">Accéder à la carte complète</a>
                            <Link class="btn btn-outline-success btn-sm" href="/abc">Tous les ABC — liste filtrable</Link>
                            <span class="sep" aria-hidden="true"></span>
                            <Link class="lien" href="/a-propos">En savoir plus</Link>
                            <Link class="lien" href="/actualites">Suivre l'actualité</Link>
                            <Link class="lien" href="/verify">Page de vérification</Link>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <section v-if="dernieresActualites.length" class="home-actus" aria-label="Dernières actualités">
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
                </div>

                <div class="home-reperes">
                    <div class="home-reperes-grid">
                        <div class="repere">
                            <span class="n d-block">{{ fmtNb(meta?.countProjets) }}</span>
                            <span class="l d-block">projets ABC recensés, métropole et outre-mer</span>
                        </div>
                        <div class="repere">
                            <span class="n d-block">{{ fmtNb(statuts.actives) }}</span>
                            <span class="l d-block">démarches actives : en cours ou à venir</span>
                            <div class="barre">
                                <i :style="{ width: `${pct(statuts.aVenir)}%`, background: COLOR.a_venir }"></i>
                                <i :style="{ width: `${pct(statuts.enCours)}%`, background: COLOR.en_cours }"></i>
                                <i :style="{ width: `${pct(statuts.termines)}%`, background: COLOR.termine }"></i>
                            </div>
                            <span class="legende d-block mt-1">
                                <b>{{ fmtNb(statuts.aVenir) }}</b> à venir ·
                                <b>{{ fmtNb(statuts.enCours) }}</b> en cours ·
                                <b>{{ fmtNb(statuts.termines) }}</b> terminées
                            </span>
                        </div>
                        <div class="repere">
                            <span class="n d-block">3</span>
                            <span class="l d-block">sources publiques croisées</span>
                            <span class="detail d-block">registre OFB, Fonds vert, archives 2022</span>
                        </div>
                        <div class="repere">
                            <span class="n d-block">{{ derniereConsolidation }}</span>
                            <span class="l d-block">dernière consolidation</span>
                            <span class="detail d-block">collecte automatique + vérification manuelle</span>
                        </div>
                    </div>
                    <p class="note-fin mb-0">
                        Données publiques sous <a href="https://www.etalab.gouv.fr/licence-ouverte-open-licence/" rel="noopener" target="_blank">Licence Ouverte</a>, librement réutilisables.
                    </p>
                    <p v-if="meta?.countNonLocalises" class="small text-muted mt-2 mb-0">
                        {{ meta.countNonLocalises }} projet{{ meta.countNonLocalises > 1 ? 's' : '' }}
                        {{ meta.countNonLocalises > 1 ? 'figurent' : 'figure' }} dans les statistiques mais pas
                        sur la carte : leurs communes n'ont pas pu être géolocalisées.
                    </p>
                </div>

                <h2 class="home-band-title">Contribuer à l'Observatoire</h2>
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="contribute-card">
                            <h2><i class="bi bi-pencil-square" aria-hidden="true"></i> Votre connaissance du terrain</h2>
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
                    <div class="col-lg-7">
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
.home-content h1 { font-size: 2rem; font-weight: 700; color: #14532d; margin-bottom: 1rem; }
.home-content h2 { color: #14532d; }
.home-subtitle { font-size: 1rem; color: #475569; margin: .35rem 0 0; }
/* Appels à l'action : bouton principal rempli, liste en contour, renvois discrets.
   margin-top:auto colle la rangée en bas de colonne, au niveau de l'encadré actualités. */
.home-cta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: auto; padding-top: 1.25rem; }
.home-cta .sep { width: 1px; height: 1.4rem; background: #e2e8f0; margin: 0 .35rem; }
.home-cta .lien { font-size: .85rem; color: #14532d; font-weight: 600; text-decoration: none; padding-right: .6rem; }
.home-cta .lien:hover { text-decoration: underline; }
.home-cta .lien + .lien { border-left: 1px solid #cbd5e1; padding-left: .6rem; }
/* Grand chiffre (sous-colonne, à côté du titre) : communes couvertes */
.home-bignum { border-left: 4px solid #689f38; padding-left: 1.25rem; }
.home-bignum .n {
    font-size: 3rem; font-weight: 800; color: #14532d; line-height: 1;
    font-variant-numeric: tabular-nums; letter-spacing: -.02em;
}
.home-bignum .l { font-size: .88rem; color: #475569; font-weight: 600; }
.home-bignum .s { font-size: .75rem; color: #94a3b8; }
/* Bande de repères : quatre chiffres, une colonne chacun */
.home-reperes { border-top: 1px solid #e2e8f0; margin-top: 2.5rem; padding-top: 1.5rem; }
.home-reperes-grid { display: grid; grid-template-columns: 2fr 3fr 1.4fr 1.6fr; gap: 2rem; }
.repere { border-left: 1px solid #e2e8f0; padding-left: 1.25rem; }
.repere:first-child { border-left: none; padding-left: 0; }
.repere .n {
    display: block; font-size: 1.7rem; font-weight: 800; color: #14532d;
    line-height: 1.1; font-variant-numeric: tabular-nums;
}
.repere .l { display: block; font-size: .78rem; color: #64748b; margin-top: .1rem; }
.repere .barre { display: flex; height: 8px; border-radius: 999px; overflow: hidden; background: #e2e8f0; margin: .45rem 0 .3rem; }
.repere .barre i { display: block; }
.repere .legende { font-size: .72rem; color: #64748b; }
.repere .legende b { color: #334155; font-variant-numeric: tabular-nums; }
.repere .detail { display: block; font-size: .72rem; color: #94a3b8; margin-top: .15rem; }
.note-fin { font-size: .8rem; color: #94a3b8; margin-top: 1.25rem; }
.note-fin a { color: #64748b; }
@media (max-width: 991.98px) {
    .home-reperes-grid { grid-template-columns: 1fr 1fr; }
    .repere:nth-child(3) { border-left: none; padding-left: 0; }
}
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