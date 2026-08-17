<script setup>
import Layout from '../Components/Layout.vue';

defineProps({
    actualites: {
        type: Array,
        default: [],
    },
});

const formatDate = (d) =>
    d ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(d)) : '';
</script>

<template>
    <Layout>
        <div class="container py-4">
            <h1 class="h4 mb-4">Actualités</h1>
            <div v-if="actualites.length === 0" class="text-muted">Aucune actualité publiée pour le moment.</div>
            <div v-for="a in actualites" :key="a.id" class="card mb-3 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <h2 class="h5 mb-0">{{ a.titre }}</h2>
                        <small class="text-muted text-nowrap">{{ formatDate(a.date_publication) }}</small>
                    </div>
                    <div class="text-muted" style="white-space: pre-wrap;">{{ a.contenu }}</div>
                </div>
            </div>
        </div>
    </Layout>
</template>