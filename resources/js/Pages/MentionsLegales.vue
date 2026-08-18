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
                « {{ site.name }} » est un projet ouvert consacré au suivi des Atlas de la Biodiversité Communale (ABC).
                Contact pour toute question : par le formulaire de suggestion depuis la carte, ou via la page
                <a href="/actualites">Actualités</a>.
            </p>

            <h2 class="h6">Hébergement</h2>
            <p>Le site est hébergé chez l'hébergeur du domaine observatoire-abc.fr.</p>

            <h2 class="h6">Données personnelles</h2>
            <p>
                Ce site ne collecte pas de données personnelles en dehors des informations que vous consentez à fournir
                (proposition de correction, connexion au panneau d'administration). Voir la page
                <a href="/confidentialite">Politique de confidentialité</a>.
            </p>

            <h2 class="h6">Données et licence</h2>
            <p>
                Les données proviennent des registres publics <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>
                (Registre OFB, Fonds vert). Elles sont réutilisées telles que publiées par leurs producteurs.
                L'Observatoire n'est pas l'auteur de ces données et n'en modifie pas la licence d'origine.
            </p>
        </div>
    </Layout>
</template>
