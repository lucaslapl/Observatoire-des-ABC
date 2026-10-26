<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
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
        <div class="container py-4 doc-content" style="max-width: 760px;">
            <h1 class="h3 mb-4">Mentions légales</h1>

            <h2>Éditeur du site</h2>
            <p>
                « {{ site.name }} » est un projet ouvert développé et édité par
                <a href="https://lucaslaplanche.fr" rel="noopener" target="_blank">Lucas Laplanche</a>,
                consacré au suivi des Atlas de la Biodiversité Communale (ABC).
                Contact pour toute question : via la page <a href="/contact">Contact</a>.
            </p>

            <h2>Hébergement</h2>
            <p>
                Le site est publié à l'adresse observatoire-abc.fr et hébergé par
                <a href="https://pulseheberg.com" rel="noopener" target="_blank">PulseHeberg</a>,
                SAS au 9, Boulevard de Strasbourg, 83000 Toulon (RCS Toulon 824 070 619,
                SIRET 824 070 619 00039, TVA FR29824070619) —
                <a href="https://pulseheberg.com/legal" rel="noopener" target="_blank">mentions légales de l'hébergeur</a>.
            </p>

            <h2>Données personnelles</h2>
            <p>
                Ce site ne collecte des données personnelles que dans les cas suivants :
                proposition de correction depuis la carte (adresse IP et navigateur enregistrés
                pour la modération), envoi du <a href="/contact">formulaire de contact</a>
                (nom facultatif, adresse email, message), création d'un compte membre, et
                connexion au panneau d'administration.
                Voir la page <a href="/confidentialite">Politique de confidentialité</a>.
            </p>

            <h2>Données et licence</h2>
            <p>
                Les données proviennent des registres publics <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>
                (Registre OFB, Fonds vert) et des archives publiques de ces mêmes sources
                (Wayback Machine, millésime 2022). Elles sont réutilisées telles que publiées par leurs producteurs.
                L'Observatoire n'est pas l'auteur de ces données et n'en modifie pas la licence d'origine.
            </p>
            <p>
                Les corrections proposées par les contributeurs, une fois validées, sont intégrées aux
                données publiées de l'Observatoire et rediffusées avec elles — site, exports et
                <Link href="/api">API publique</Link> — sous
                <a href="https://www.etalab.gouv.fr/wp-content/uploads/2017/04/ETALAB-Licence-Ouverte-v2.0.pdf" rel="noopener" target="_blank">Licence Ouverte 2.0 (Etalab)</a>,
                avec un crédit collectif (« les contributeurs de l'Observatoire des ABC »), jamais nominatif.
                En proposant une correction, chaque contributeur l'accepte explicitement :
                une case à cocher du formulaire le rappelle et l'acceptation est enregistrée avec la contribution.
            </p>
            <h2>Code source</h2>
            <p>
                Le code source de l'Observatoire est publié sous licence libre
                <strong>AGPL-3.0</strong>. Les versions modifiées diffusées en ligne doivent
                publier leurs sources.
            </p>
        </div>
    </Layout>
</template>
