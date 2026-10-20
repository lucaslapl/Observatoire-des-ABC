<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const page = usePage();
const site = computed(() => page.props.site || {});
</script>

<template>
    <Layout>
        <Seo
            title="Mentions légales"
            description="Mentions légales de l'Observatoire des ABC : éditeur, hébergement, données et licence des Atlas de la Biodiversité Communale."
        />
        <div class="container py-4" style="max-width: 760px;">
            <h1 class="h3 mb-4">Mentions légales</h1>

            <h2 class="h6">Éditeur du site</h2>
            <p>
                « {{ site.name }} » est un projet ouvert développé et édité par
                <a href="https://lucaslaplanche.fr" rel="noopener" target="_blank">Lucas Laplanche</a>,
                consacré au suivi des Atlas de la Biodiversité Communale (ABC).
                Contact pour toute question : via la page <a href="/contact">Contact</a>.
            </p>

            <h2 class="h6">Hébergement</h2>
            <p>Le site est publié à l'adresse observatoire-abc.fr, hébergée par l'hébergeur du domaine.</p>

            <h2 class="h6">Données personnelles</h2>
            <p>
                Ce site ne collecte des données personnelles que dans les cas suivants :
                proposition de correction depuis la carte (adresse IP et navigateur enregistrés
                pour la modération), envoi du <a href="/contact">formulaire de contact</a>
                (nom facultatif, adresse email, message), création d'un compte membre, et
                connexion au panneau d'administration.
                Voir la page <a href="/confidentialite">Politique de confidentialité</a>.
            </p>

            <h2 class="h6">Données et licence</h2>
            <p>
                Les données proviennent des registres publics <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>
                (Registre OFB, Fonds vert) et des archives publiques de ces mêmes sources
                (Wayback Machine, millésime 2022). Elles sont réutilisées telles que publiées par leurs producteurs.
                L'Observatoire n'est pas l'auteur de ces données et n'en modifie pas la licence d'origine.
            </p>
            <h2 class="h6">Code source</h2>
            <p>
                Le code source de l'Observatoire est publié sous licence libre
                <strong>AGPL-3.0</strong>. Les versions modifiées diffusées en ligne doivent
                publier leurs sources.
            </p>
        </div>
    </Layout>
</template>
