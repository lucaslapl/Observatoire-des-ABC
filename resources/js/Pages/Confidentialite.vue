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
            title="Politique de confidentialité"
            description="Politique de confidentialité de l'Observatoire des ABC : cookies, mesure d'audience, contributions et données."
        />
        <div class="container py-4" style="max-width: 760px;">
            <h1 class="h3 mb-4">Politique de confidentialité</h1>

            <h2 class="h6">Ce que nous collectons</h2>
            <p>
                La consultation de la carte ne nécessite aucune donnée personnelle. Les propositions de correction
                (contribution) n'exigent pas de compte : vous pouvez y indiquer un commentaire si vous le souhaitez.
                Les comptes (contributeur, modérateur, administrateur) sont réservés aux personnes habilitées.
            </p>

            <h2 class="h6">Cookies & mesure d'audience</h2>
            <p>
                Une bannière de consentement vous permet de choisir l'activation de la mesure d'audience
                (statistiques de fréquentation agrégées). Sans votre consentement, aucun traceur de mesure
                d'audience n'est chargé. Le cookie de session de connexion est strictement nécessaire au
                fonctionnement du panneau d'administration.
            </p>

            <h2 class="h6">Conservation et droits</h2>
            <p>
                Vous disposez d'un droit d'accès, de rectification et de suppression des données vous concernant,
                en nous contactant via le formulaire de la carte. Les adresses IP des contributions sont stockées
                le temps de la modération puis anonymisées.
            </p>
        </div>
    </Layout>
</template>
