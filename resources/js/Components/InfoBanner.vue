<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="info-banner py-2" role="note">
        <div class="container-fluid d-flex flex-wrap align-items-center gap-2 justify-content-center text-center">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>
                Projet indépendant en évolution continue —
                <Link href="/contact">signalez toute erreur</Link>.
            </span>
        </div>
    </div>
</template>

<style>
.info-banner {
    background: #f0fdf4;
    border-bottom: 1px solid #bbf7d0;
    color: #14532d;
    font-size: 0.85rem;
}
.info-banner a { color: #166534; font-weight: 600; }
.info-banner a:hover { color: #14532d; text-decoration: underline; }
</style>
