<script setup>
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

defineProps({
    chiffres: { type: Object, default: () => ({}) },
});

const fmt = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0);
</script>

<template>
    <Layout>
        <Seo
            title="À propos"
            description="Pourquoi et comment l'Observatoire des ABC a été créé : sources publiques (OFB, Fonds vert, data.gouv.fr), vérification manuelle, projet indépendant né de l'indisponibilité d'abc.naturefrance.fr."
        />
        <div class="container py-4" style="max-width: 760px;">
            <h1 class="h3 mb-4">À propos</h1>

            <h2 class="h6">Pourquoi ce site existe</h2>
            <p>
                L'Observatoire des ABC est né d'un constat simple : le site de référence
                <a href="https://abc.naturefrance.fr" rel="noopener" target="_blank">abc.naturefrance.fr</a>,
                qui recensait les Atlas de la Biodiversité Communale, est devenu indisponible
                <strong>à l'été 2025</strong>, à la suite de la cyberattaque qui a touché les systèmes
                d'information du
                <a href="https://www.mnhn.fr" rel="noopener" target="_blank">Muséum national d'Histoire naturelle</a> (MNHN).
                Des informations publiques utiles aux communes, aux naturalistes et aux curieux se sont
                retrouvées inaccessibles du jour au lendemain, sans alternative pour consulter la liste
                des projets ABC et leur avancement, à moins de récupérer les données brutes mais difficilement exploitables.
            </p>
            <p>
                Ce site propose donc un recensement indépendant des projets ABC : qui ils sont, où ils
                se trouvent, à quel stade ils en sont. Il ne s'agit pas d'une reprise du site officiel, mais
                d'une reconstruction à partir des registres publics, accessible à tous même pendant
                l'indisponibilité de la source historique.
            </p>
            <div class="row text-center my-4" v-if="chiffres?.projets">
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.projets) }}</div>
                    <div class="small text-muted">projets ABC suivis</div>
                </div>
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.communes) }}</div>
                    <div class="small text-muted">communes couvertes</div>
                </div>
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.verifies) }}</div>
                    <div class="small text-muted">projets vérifiés manuellement</div>
                </div>
            </div>

            <h2 class="h6">D'où viennent les données</h2>
            <p>
                Les données proviennent exclusivement de registres publics :
                <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>
                (listes de projets ABC financés), les données publiées par l'
                <a href="https://www.ofb.gouv.fr" rel="noopener" target="_blank">Office français de la biodiversité</a> (OFB)
                et la liste des lauréats du
                <a href="https://www.ecologie.gouv.fr/dispositif-fonds-vert" rel="noopener" target="_blank">Fonds vert</a>,
                ainsi que les archives du site historique conservées par la
                <a href="https://web.archive.org" rel="noopener" target="_blank">Wayback Machine</a> (instantanés 2022).
                Aucune donnée privée ni confidentielle n'est utilisée. Ces données sont publiées
                sous <a href="https://www.etalab.gouv.fr/licence-ouverte-open-licence/" rel="noopener" target="_blank">Licence Ouverte</a> :
                leur réutilisation est libre, y compris commercialement, sous réserve de mentionner la source.
            </p>
            <p>
                Les données collectées automatiquement sont ensuite <strong>vérifiées manuellement</strong> :
                les projets signalés comme suspects sont examinés un par un, et les visiteurs peuvent
                proposer des corrections depuis la fiche de chaque projet. Ce travail humain —
                vérifications et contributions — prime toujours sur la collecte automatique :
                il n'est jamais écrasé par une mise à jour des sources.
            </p>

            <h2 class="h6">Comment le site a été développé</h2>
            <p>
                Le code du site a été développé avec l'assistance d'un outil IA
                (<a href="https://mistral.ai" rel="noopener" target="_blank">Mistral Code</a>),
                avec une revue humaine sur chaque contribution et chaque modification avant publication.
                Le code source est librement consultable sur
                <a href="https://github.com/lucaslapl/Observatoire-des-ABC" rel="noopener" target="_blank">GitHub</a>.
            </p>

            <h2 class="h6">Un projet indépendant</h2>
            <p>
                L'Observatoire des ABC est un projet citoyen indépendant : il n'est ni édité, ni animé par
                le MNHN, l'OFB ou tout autre organisme public. Pour toute question ou suggestion,
                la page <a href="/contact">Contact</a> est à votre disposition — et si vous constatez
                une erreur sur un projet, la meilleure façon de la signaler est de contribuer
                directement depuis sa fiche.
            </p>
        </div>
    </Layout>
</template>
