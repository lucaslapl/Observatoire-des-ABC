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
                (contribution) exigent un compte dont l'adresse email est vérifiée ; vous pouvez y ajouter un
                commentaire si vous le souhaitez.
                À des fins de modération (lutte contre le spam et les propositions malveillantes), l'adresse IP
                et le type de navigateur (user-agent) sont enregistrées avec chaque proposition.
            </p>
            <p>
                Le <a href="/contact">formulaire de contact</a> collecte une adresse email (obligatoire),
                un nom (facultatif) et votre message ; ils servent uniquement à vous répondre.
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
                Ce site utilise une mesure d'audience anonyme (Matomo, auto-hébergé) : statistiques
                de fréquentation agrégées, sans revente ni recoupement de données. Au titre de
                l'exemption dédiée à la mesure d'audience (RGPD), elle est active par défaut —
                vous pouvez vous y opposer à tout moment via le lien « Cookies » présent en pied
                de page de chaque page. Votre choix est mémorisé dans votre navigateur (stockage
                local, clé « abc-consent »). Le cookie de session de connexion est strictement nécessaire au
                fonctionnement de l'espace membre et du panneau d'administration.
            </p>

            <h2 class="h6">Conservation des données</h2>
            <p>
                Les contributions et leurs métadonnées de modération (adresse IP, user-agent) sont conservées
                pendant toute la durée nécessaire à la modération des propositions. Les comptes sont conservés
                jusqu'à leur suppression par leur titulaire. Les messages de contact sont conservés le temps de
                leur traitement, dans la boîte mail du destinataire.
            </p>

            <h2 class="h6">Vos droits</h2>
            <p>
                Vous disposez d'un droit d'accès, de rectification et de suppression des données vous concernant.
                Pour l'exercer, écrivez-nous via le <a href="/contact">formulaire de contact</a> ; pour vos
                données de compte, vous pouvez aussi agir directement depuis votre espace membre
                (mot de passe, suppression du compte). Vous pouvez introduire une réclamation auprès de la
                CNIL (<a href="https://www.cnil.fr" rel="noopener" target="_blank">cnil.fr</a>).
            </p>
        </div>
    </Layout>
</template>
