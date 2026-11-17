<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

// Contenu de la FAQ — tout est modifiable ici.
// `label` = entrée du sommaire, `titre` = question affichée, `reponse` = HTML
// de la réponse (les liens internes en href="/..." sont interceptés par
// clicLien ci-dessous pour une navigation SPA sans rechargement).
const faqs = [
    {
        id: 'q1',
        num: '01',
        label: "Qu'est-ce qu'un ABC ?",
        titre: "Qu'est-ce qu'un Atlas de la biodiversité communale (ABC) ?",
        reponse: `
            <p>
                Un <strong>Atlas de la biodiversité communale (ABC)</strong> est un inventaire de la faune
                et de la flore du territoire d'une commune (ou d'un groupement de communes), mené avec
                ses habitants, ses associations et ses naturalistes. C'est une <strong>démarche volontaire
                de la collectivité</strong> : elle dresse un diagnostic de la biodiversité locale, sensibilise
                les habitants, et se conclut par la <strong>publication d'un atlas et d'un plan d'actions</strong>
                pour la préserver. L'État accompagne ces démarches : les projets sont recensés dans le
                registre national tenu par l'<a href="https://www.ofb.gouv.fr" rel="noopener" target="_blank">Office français de la biodiversité (OFB)</a>.
            </p>
        `,
    },
    {
        id: 'q2',
        num: '02',
        label: 'Qui lance un ABC, comment est-ce financé ?',
        titre: 'Qui peut lancer un ABC et comment est-ce financé ?',
        reponse: `
            <p>
                Toute <strong>commune ou intercommunalité</strong> peut s'engager volontairement dans un ABC.
                Le financement vient le plus souvent de l'État : les lauréats du
                <a href="https://www.ecologie.gouv.fr/dispositif-fonds-vert" rel="noopener" target="_blank">Fonds vert</a>
                (mesure « P113 ») et les projets inscrits au registre national de l'OFB publié sur
                <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>.
                Certaines collectivités financent aussi leur démarche sur fonds propres.
            </p>
        `,
    },
    {
        id: 'q3',
        num: '03',
        label: 'Combien de temps dure un ABC ?',
        titre: 'Combien de temps dure un ABC ?',
        reponse: `
            <p>
                En général <strong>environ trois ans</strong> : le temps du diagnostic, des inventaires de
                terrain, de la concertation avec les habitants, puis de la rédaction et de la publication
                de l'atlas et du plan d'actions. Certaines communes peuvent volontairement prolonger la démarche au-delà des trois ans pour suivre la mise en œuvre du plan d'actions. Il existe également des projets plus courts, tels que les IBC (Inventaires de la biodiversité communale).
            </p>
        `,
    },
    {
        id: 'q4',
        num: '04',
        label: "Qu'est-ce que l'Observatoire des ABC ?",
        titre: "Qu'est-ce que l'Observatoire des ABC ?",
        reponse: `
            <p>
                C'est un <strong>projet citoyen indépendant</strong> qui recense et suit les projets ABC en
                France : où ils se trouvent, qui les porte, sur quelle période, et à quel stade ils en sont
                (à venir, en cours, terminé). Il est né <strong>à l'été 2026</strong>, un an après que le site de référence
                <a href="https://abc.naturefrance.fr" rel="noopener" target="_blank">abc.naturefrance.fr</a>
                soit devenu indisponible après la cyberattaque qui a touché le Muséum national d'Histoire
                naturelle. Ce n'est pas une reprise du site officiel : c'est une reconstruction à partir des
                registres publics, ouverte à tous. Pour en savoir plus, voir la page
                <a href="/a-propos">À propos</a>.
            </p>
        `,
    },
    {
        id: 'q5',
        num: '05',
        label: "D'où viennent les données ?",
        titre: "D'où viennent les données affichées sur le site ?",
        reponse: `
            <p>
                Exclusivement de <strong>registres publics</strong> : le registre national des ABC (OFB) sur
                data.gouv.fr, l'archive 2022 de l'ancien site abc.naturefrance.fr conservée par la
                <a href="https://web.archive.org" rel="noopener" target="_blank">Wayback Machine</a>,
                et les listes de lauréats du Fonds vert (2024 et 2025). Aucune donnée privée n'est utilisée.
                Le détail du parcours des données est sur la page
                <a href="/methodologie">Méthodologie</a>.
            </p>
        `,
    },
    {
        id: 'q6',
        num: '06',
        label: 'Les données sont-elles fiables ?',
        titre: 'Ces données sont-elles fiables ?',
        reponse: `
            <p>
                Elles sont collectées automatiquement, puis <strong>vérifiées à la main</strong>, un travail de longue haleine : les projets
                suspects sont examinés un par un (la liste est publique sur la page
                <a href="/verify">Vérification</a>), et chaque fiche peut recevoir des corrections de la part
                des visiteurs. Ce travail humain <strong>prime toujours</strong> sur la collecte automatique (il n'est jamais écrasé par une mise à jour) et chaque correction est conservée dans un
                journal d'audit.
            </p>
        `,
    },
    {
        id: 'q7',
        num: '07',
        label: 'Comment signaler une erreur ?',
        titre: 'Comment signaler une erreur ou contribuer ?',
        reponse: `
            <p>
                Depuis la fiche de chaque projet ABC, un formulaire permet de <strong>proposer une correction</strong>
                (dates, statut, structure porteuse). L'inscription au site est <a href="/register">ouverte</a>
                (vérification de l'email requise) et donne le rôle de contributeur ; chaque proposition est
                examinée par une équipe de modération avant d'être appliquée. On peut aussi simplement écrire
                via la page <a href="/contact">Contact</a>.
            </p>
        `,
    },
    {
        id: 'q8',
        num: '08',
        label: 'Puis-je réutiliser les données ?',
        titre: 'Puis-je réutiliser les données du site ?',
        reponse: `
            <p>
                Oui. L'<strong>API publique</strong> (GeoJSON, métadonnées) et les exports CSV/GeoJSON sont
                libres de droits : réutilisation gratuite, y compris commerciale, sous
                <a href="https://www.etalab.gouv.fr/licence-ouverte-open-licence/" rel="noopener" target="_blank">Licence Ouverte</a>,
                à condition de mentionner la source. Le détail est sur la page
                <a href="/api">API &amp; réutilisation</a>.
            </p>
        `,
    },
];

// Section active du sommaire, suivie au scroll.
const activeId = ref(faqs[0].id);

let observateur;
onMounted(() => {
    observateur = new IntersectionObserver((entrees) => {
        for (const entree of entrees) {
            if (entree.isIntersecting) activeId.value = entree.target.id;
        }
    }, { rootMargin: '-15% 0px -75% 0px' });
    document.querySelectorAll('.faq-section').forEach((s) => observateur.observe(s));
});
onBeforeUnmount(() => observateur?.disconnect());

function scrollTo(id) {
    activeId.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    history.replaceState(null, '', `#${id}`);
}

// Les réponses étant en v-html, on délègue les liens internes à Inertia.
function clicLien(e) {
    const a = e.target.closest('a');
    if (!a || a.target) return;
    const href = a.getAttribute('href') || '';
    if (href.startsWith('/')) {
        e.preventDefault();
        router.visit(href);
    }
}

const sansHtml = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

// Données structurées FAQPage pour les moteurs de recherche.
const faqJsonLd = computed(() => ({
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((f) => ({
        '@type': 'Question',
        name: f.titre,
        acceptedAnswer: { '@type': 'Answer', text: sansHtml(f.reponse) },
    })),
}));
</script>

<template>
    <Layout>
        <Seo
            title="Questions fréquentes (FAQ)"
            description="Ce qu'est un Atlas de la biodiversité communale (ABC), ce que fait l'Observatoire des ABC, d'où viennent les données et comment contribuer : les réponses aux questions les plus fréquentes."
            :json-ld="faqJsonLd"
        />
        <div class="container py-4" style="max-width: 1080px;" @click="clicLien">
            <h1 class="h3 page-title mb-2">Questions fréquentes</h1>
            <p class="text-muted mb-4">
                Ce qu'est un ABC, ce que fait l'Observatoire des ABC, d'où viennent les données
                et comment y contribuer.
            </p>

            <div class="row">
                <div class="col-lg-4 col-xl-3">
                    <nav class="faq-sommaire sticky-top d-none d-lg-block" aria-label="Sommaire de la FAQ">
                        <p class="sommaire-titre">Sommaire</p>
                        <a
                            v-for="f in faqs"
                            :key="f.id"
                            :href="'#' + f.id"
                            class="nav-link"
                            :class="{ active: activeId === f.id }"
                            @click.prevent="scrollTo(f.id)"
                        >{{ f.label }}</a>
                    </nav>
                </div>
                <div class="col-lg-8 col-xl-9">
                    <section v-for="f in faqs" :key="f.id" :id="f.id" class="faq-section">
                        <h2><span class="num">{{ f.num }}</span>{{ f.titre }}</h2>
                        <div v-html="f.reponse"></div>
                    </section>

                    <div class="faq-cta my-5 p-4 p-md-5 rounded-3 text-center">
                        <h2 class="h5 mb-2 page-title">Une question restée sans réponse ?</h2>
                        <p class="text-muted mb-4">
                            Écrivez-nous via la page Contact — ou proposez directement une correction
                            depuis la fiche du projet concerné.
                        </p>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <Link href="/contact" class="btn btn-success btn-sm">Nous contacter</Link>
                            <Link href="/carte" class="btn btn-outline-success btn-sm">Voir la carte des ABC</Link>
                            <Link href="/abc" class="btn btn-outline-success btn-sm">Tous les ABC</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.page-title { color: #14532d; font-weight: 700; }

/* Sommaire sticky à gauche, façon documentation */
.faq-sommaire { top: 1.25rem; }
.faq-sommaire .sommaire-titre {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    margin-bottom: 0.5rem;
}
.faq-sommaire .nav-link {
    display: block;
    color: #475569;
    text-decoration: none;
    font-size: 0.875rem;
    padding: 0.3rem 0 0.3rem 0.9rem;
    border-left: 2px solid #e2e8f0;
}
.faq-sommaire .nav-link:hover { color: #14532d; }
.faq-sommaire .nav-link.active { color: #14532d; font-weight: 600; border-left-color: #14532d; }

/* Sections de questions à droite */
.faq-section {
    scroll-margin-top: 1.25rem;
    padding: 1.25rem 0 1.5rem;
    border-bottom: 1px solid #eef2f0;
}
.faq-section:last-of-type { border-bottom: 0; }
.faq-section h2 { font-size: 1.05rem; font-weight: 700; color: #14532d; margin-bottom: 0.5rem; }
.faq-section h2 .num { color: #94a3b8; font-weight: 600; margin-right: 0.4rem; }

.faq-cta { background: #f0f7f1; border: 1px solid #d7e8dc; }
</style>
