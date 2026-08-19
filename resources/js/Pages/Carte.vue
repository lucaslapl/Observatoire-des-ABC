<script setup>
import Seo from '../Components/Seo.vue';
import MapExplorer from '../Components/MapExplorer.vue';

defineProps({
    meta: {
        type: Object,
        default: null,
    },
    isAdmin: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="d-flex flex-column carte-page">
        <Seo
            title="Carte des Atlas de la Biodiversité Communale (ABC)"
            description="Carte interactive complète des Atlas de la Biodiversité Communale (ABC) en France : filtres par statut, région, période et commune, légende détaillée."
        />
        <MapExplorer :meta="meta" :is-admin="isAdmin" :compact-legend="false" :full-page="true" />
    </div>
</template>

<style>
.carte-page { min-height: 100vh; }
</style>