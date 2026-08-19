<script setup>
import { Link } from '@inertiajs/vue3';
import ConsentBanner from './ConsentBanner.vue';

defineProps({
    title: String,
});
const pages = [
    { name: 'home', component: 'Map', label: 'Accueil', icon: 'bi-house', href: '/' },
    { name: 'Carte', component: 'Carte', label: 'Carte des ABC', icon: 'bi-map', href: '/carte' },
    { name: 'verify', component: 'Verify', label: 'Vérification', icon: 'bi-clipboard-check', href: '/verify' },
    { name: 'actualites', component: 'Actualites', label: 'Actualités', icon: 'bi-newspaper', href: '/actualites' },
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
                            <Link :href="p.href"
                                class="nav-link px-2 d-flex align-items-center gap-2" :class="{ active: $page.component === p.component }">
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

        <footer class="site-footer py-3">
            <div class="container small text-center">
                <div class="mb-1">
                    Observatoire des ABC — données réutilisées en lisant
                    <a href="https://data.gouv.fr" rel="noopener">data.gouv.fr</a>.
                </div>
                <div class="mb-1">
                    Développé par <a href="https://lucaslaplanche.fr" target="_blank" rel="noopener">Lucas LAPLANCHE</a>
                    · Code source sur <a href="https://github.com/lucaslapl/Observatoire-des-ABC" target="_blank" rel="noopener">GitHub</a>
                </div>
                <div>
                    <Link href="/carte">Carte</Link>&nbsp;·
                    <Link href="/actualites">Actualités</Link>&nbsp;·
                    <Link href="/mentions-legales">Mentions légales</Link>&nbsp;·
                    <Link href="/confidentialite">Confidentialité</Link>
                </div>
            </div>
        </footer>

        <ConsentBanner />
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }
.site-footer { background: #14532d; color: #e8f5e9; }
.site-footer a { color: #fff; }
.site-footer a:hover { color: #f0fdf4; }
</style>