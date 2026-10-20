<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="dev-banner py-2" role="note">
        <div class="container-fluid d-flex flex-wrap align-items-center gap-2 justify-content-center text-center">
            <i class="bi bi-cone-striped" aria-hidden="true"></i>
            <span>
                Site en cours de développement&nbsp;: de courtes interruptions peuvent survenir en cours de journée.
                <Link href="/contact">Un bug&nbsp;? Signalez-le</Link>.
            </span>
        </div>
    </div>
</template>

<style>
.dev-banner {
    background: #fef3c7;
    border-bottom: 1px solid #fcd34d;
    color: #78350f;
    font-size: 0.85rem;
}
.dev-banner a { color: #92400e; font-weight: 600; }
.dev-banner a:hover { color: #78250f; text-decoration: underline; }
</style>
