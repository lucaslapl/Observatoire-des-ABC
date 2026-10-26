<script setup>
import { computed, ref } from 'vue';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const endpoints = [
    { m: 'GET', p: '/api/v1/projets', d: 'Liste des projets, paginée par curseur. Filtres : statut, annee, code_insee, departement, region, porteur, bbox. GeoJSON possible.' },
    { m: 'GET', p: '/api/v1/projets/{id}', d: 'Fiche détaillée d\'un projet : communes, vérification, historique, provenance.' },
    { m: 'GET', p: '/api/v1/communes/{code_insee}', d: 'Fiche d\'une commune et projets liés. GeoJSON possible.' },
    { m: 'GET', p: '/api/v1/departements/{code}', d: 'Synthèse d\'un département : communes et projets couverts.' },
    { m: 'GET', p: '/api/v1/regions/{code}', d: 'Synthèse d\'une région (slug, code INSEE ou libellé).' },
    { m: 'GET', p: '/api/v1/journal', d: 'Journal public d\'audit : vérifications, contributions validées, fusions — dans le même ordre que sur le site.' },
    { m: 'GET', p: '/api/v1/stats', d: 'Compteurs globaux : projets, communes, vérifications, anomalies.' },
];

const today = new Intl.DateTimeFormat('fr-FR').format(new Date());
const credit = computed(() => `Source : Observatoire des ABC (observatoire-abc.fr), enrichi par ses contributeurs, d'après les données de l'OFB et du Fonds vert — sous Licence Ouverte Etalab 2.0. Mise à jour : ${today}.`);

const copied = ref(false);
function copyCredit() {
    navigator.clipboard?.writeText(credit.value).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}
</script>

<template>
    <Layout>
        <Seo
            title="API publique et réutilisation des données"
            description="API publique v1 de l'Observatoire des ABC en lecture seule : projets, communes, départements, régions, journal d'audit et statistiques, sous Licence Ouverte 2.0 (Etalab). Documentation, crédit attendu et conditions d'utilisation."
        />
        <div class="container py-4 doc-content" style="max-width: 760px;">
            <h1 class="h3 mb-4">API publique &amp; conditions d'utilisation</h1>

            <p class="lead">
                L'Observatoire des ABC propose une <strong>API publique en lecture seule</strong>
                pour permettre aux sites tiers, aux collectivités et aux chercheurs de réutiliser
                les données — y compris les corrections et ajouts des contributeurs —
                en créditant la source. Toutes les données sont réutilisables sous
                <a href="https://www.etalab.gouv.fr/wp-content/uploads/2017/04/ETALAB-Licence-Ouverte-v2.0.pdf" rel="noopener" target="_blank">Licence&nbsp;Ouverte&nbsp;2.0 (Etalab)</a>.
            </p>

            <h2>Endpoints (v1)</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr><th scope="col">Méthode</th><th scope="col">Endpoint</th><th scope="col">Description</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in endpoints" :key="e.p">
                            <td><span class="badge bg-success">{{ e.m }}</span></td>
                            <td><code>{{ e.p }}</code></td>
                            <td class="small">{{ e.d }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p>
                Réponses en <strong>JSON</strong> par défaut ;
                <code>/api/v1/projets</code> et <code>/api/v1/communes/{code_insee}</code>
                acceptent aussi le <strong>GeoJSON</strong> (paramètre <code>format=geojson</code>
                ou en-tête <code>Accept: application/geo+json</code>). La pagination est
                <strong>par curseur</strong> : suivez <code>meta.next_cursor</code> ou
                <code>meta.next_page_url</code>. Chaque enregistrement expose ses métadonnées
                de provenance (<code>source_origine</code>, <code>licence</code>,
                <code>derniere_mise_a_jour</code>, <code>enrichi_par_contributeurs</code>,
                <code>statut_verification</code>).
            </p>

            <p>
                <a class="btn btn-success me-2" href="/api/doc">Documentation interactive (OpenAPI)</a>
                <a class="btn btn-outline-success" href="/api/doc/openapi.yaml">Spécification OpenAPI (YAML)</a>
            </p>

            <h2>Licence et crédit attendu</h2>
            <p>
                Les données de l'API sont sous <strong>Licence Ouverte 2.0 (Etalab)</strong> :
                réutilisation libre, y compris commerciale, sous réserve de créditer la source.
                La mention de crédit attendue, prête à copier :
            </p>
            <div class="card bg-light border-0 my-3">
                <div class="card-body">
                    <p class="mb-2 fst-italic">{{ credit }}</p>
                    <button class="btn btn-sm btn-outline-success" type="button" @click="copyCredit">
                        {{ copied ? 'Copié !' : 'Copier la mention' }}
                    </button>
                </div>
            </div>
            <p>
                Cette mention ne doit ni conférer un caractère officiel à votre réutilisation,
                ni suggérer un cautionnement par l'Observatoire des ABC, l'OFB ou le Fonds vert.
            </p>

            <h2>Absence de garantie</h2>
            <p>
                Les données sont fournies « en l'état », sans garantie d'exactitude, de
                complétude, d'actualité ni de disponibilité. L'Observatoire agrège des
                registres publics et le travail de contributeurs bénévoles : les statuts
                affichés incluent des estimations (un ABC dure environ trois ans). Croisez
                toujours avec les sources officielles avant toute décision.
            </p>

            <h2>Limites d'usage</h2>
            <ul>
                <li>Accès ouvert, sans clé : <strong>60 requêtes/minute par IP</strong> (au-delà : HTTP 429).</li>
                <li>Chaque réponse GET porte <code>ETag</code> et <code>Cache-Control</code> : renvoyez <code>If-None-Match</code> pour recevoir des <code>304</code> et alléger vos requêtes.</li>
                <li>Les réponses sont mises en cache une heure : une correction validée peut mettre jusqu'à une heure à apparaître (invalidation immédiate côté collecte et vérification).</li>
                <li>CORS ouvert : l'API est interrogeable depuis un navigateur tiers.</li>
                <li>Aucune donnée personnelle n'est exposée : les contributions sont créditées collectivement (« les contributeurs de l'Observatoire des ABC »), jamais nominativement.</li>
            </ul>

            <h2>Versionnement</h2>
            <p>
                L'API est versionnée : tout vit sous <code>/api/v1</code> et
                <strong>aucun breaking change n'interviendra dans une version publiée</strong>.
                Les ajouts de champs (non destructifs) restent possibles en v1 ; les évolutions
                incompatibles, s'il en faut, arriveront en <code>v2</code> avec une période de
                transition annoncée.
            </p>
        </div>
    </Layout>
</template>
