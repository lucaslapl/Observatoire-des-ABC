<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: String,
});
const pages = [
    { name: 'home', label: 'Carte', icon: 'bi-map' },
    { name: 'verify', label: 'Vérification', icon: 'bi-clipboard-check' },
    { name: 'actualites', label: 'Actualités', icon: 'bi-newspaper' },
];
</script>

<template>
    <div>
        <nav class="navbar navbar-dark navbar-abc navbar-expand-lg py-2">
            <div class="container-fluid">
                <Link href="/" class="navbar-brand fs-6 mb-0">🌿 Observatoire des ABC</Link>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navAbc">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navAbc">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                        <li v-for="p in pages" :key="p.name" class="nav-item">
                            <Link :href="p.name === 'home' ? '/' : '/' + p.name"
                                class="nav-link px-2 d-flex align-items-center gap-2" :class="{ active: $page.component === p.name }">
                                <i :class="['bi', p.icon]"></i> {{ p.label }}
                            </Link>
                        </li>
                        <li v-if="$page.props.auth?.user" class="nav-item d-flex align-items-center">
                            <span v-if="$page.props.auth.user.roles?.includes('admin')" class="nav-link small px-2">
                                <Link href="/" @click.prevent="$inertia.post('/logout')">Déconnexion</Link>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <main>
            <slot />
        </main>
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
</style>