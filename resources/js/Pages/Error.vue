<script setup>
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

defineProps({
    status: {
        type: Number,
        default: 404,
    },
});
</script>

<template>
    <Layout>
        <Seo noindex title="Page introuvable" description="La page demandée n'existe pas ou plus sur l'Observatoire des ABC." />
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-7 text-center">
                    <p class="display-5 fw-bold text-success mb-2">{{ status }}</p>
                    <h1 class="h4 mb-3" v-if="status === 404">Page introuvable</h1>
                    <h1 class="h4 mb-3" v-else>Une erreur est survenue</h1>
                    <p class="text-body-secondary mb-4" v-if="status === 404">
                        La page que vous cherchez n'existe pas ou a été déplacée.
                        Peut-être qu'un projet ABC a été supprimé ou exclu de l'observatoire.
                    </p>
                    <p class="text-body-secondary mb-4" v-else>
                        Une erreur inattendue est survenue de notre côté. Réessayez dans un instant.
                    </p>
                    <Link href="/" class="btn btn-success me-2">Retour à la carte</Link>
                    <Link href="/actualites" class="btn btn-outline-secondary">Voir les actualités</Link>
                </div>
            </div>
        </div>
    </Layout>
</template>
