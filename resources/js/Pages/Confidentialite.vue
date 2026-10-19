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
            </p>
            <p>
                L'inscription est ouverte à toute personne souhaitant contribuer. Un compte membre
                stocke uniquement : un nom d'utilisateur, une adresse email (vérifiée par envoi d'un
                lien) et un mot de passe haché. Les contributions soumises depuis un compte sont
                associées à celui-ci pour en faciliter la modération et le suivi.
            </p>

            <h2 class="h6">Comptes et suppression</h2>
            <p>
                Vous pouvez supprimer votre compte à tout moment depuis votre espace membre
                (après confirmation de votre mot de passe). La suppression efface le compte ;
                les contributions déjà enregistrées sont conservées et deviennent anonymes,
                car elles constituent un travail de vérification utile à la qualité des données.
                Les comptes administrateur et modérateur restent réservés aux personnes habilitées.
            </p>

            <h2 class="h6">Cookies & mesure d'audience</h2>
            <p>
                Une bannière de consentement vous permet de choisir l'activation de la mesure d'audience
                (statistiques de fréquentation agrégées). Sans votre consentement, aucun traceur de mesure
                d'audience n'est chargé. Le cookie de session de connexion est strictement nécessaire au
                fonctionnement de l'espace membre et du panneau d'administration.
            </p>

            <h2 class="h6">Conservation et droits</h2>
            <p>
                Vous disposez d'un droit d'accès, de rectification et de suppression des données vous concernant,
                en nous contactant via le formulaire de la carte ou depuis votre espace membre. Les adresses IP
                des contributions sont stockées le temps de la modération puis anonymisées.
            </p>
        </div>
    </Layout>
</template>
