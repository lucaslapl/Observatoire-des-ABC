<script setup>
import { ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandMark from './BrandMark.vue';
import ConsentBanner from './ConsentBanner.vue';
import Footer from './Footer.vue';
import InfoBanner from './InfoBanner.vue';

defineProps({
    title: String,
});
const pages = [
    { name: 'home', component: 'Map', label: 'Accueil', icon: 'bi-house', href: '/' },
    { name: 'Carte', component: 'Carte', label: 'Carte des ABC', icon: 'bi-map', href: '/carte' },
    { name: 'liste', component: 'ProjetsListe', label: 'Tous les ABC', icon: 'bi-list-ul', href: '/abc' },
    { name: 'verify', component: 'Verify', label: 'Vérification', icon: 'bi-clipboard-check', href: '/verify' },
    { name: 'actualites', component: 'Actualites', label: 'Actualités', icon: 'bi-newspaper', href: '/actualites' },
];

// Menu mobile : le JS Bootstrap n'est pas chargé (seul le CSS l'est), la
// bascule du collapse est donc pilotée par un état Vue local.
const page = usePage();
const navOpen = ref(false);
watch(() => page.component, () => {
    navOpen.value = false;
});
</script>

<template>
    <div class="d-flex flex-column min-vh-100">
        <InfoBanner />
        <nav class="navbar navbar-dark navbar-abc navbar-expand-lg py-2">
            <div class="container-fluid">
                <Link href="/" class="navbar-brand fs-6 mb-0 d-inline-flex align-items-center gap-2"><BrandMark /> Observatoire des ABC</Link>
                <button class="navbar-toggler" type="button" :aria-expanded="navOpen" aria-controls="navAbc"
                    @click="navOpen = !navOpen">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" :class="{ show: navOpen }" id="navAbc">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                        <li v-for="p in pages" :key="p.name" class="nav-item">
                            <Link :href="p.href"
                                class="nav-link px-2 d-flex align-items-center gap-2" :class="{ active: $page.component === p.component }">
                                <i :class="['bi', p.icon]"></i> {{ p.label }}
                            </Link>
                        </li>
                        <template v-if="$page.props.auth?.user">
                            <li v-if="$page.props.auth.user.roles?.includes('admin') || $page.props.auth.user.roles?.includes('moderateur')"
                                class="nav-item">
                                <Link href="/admin" class="nav-link px-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-gear"></i> Admin
                                </Link>
                            </li>
                            <li class="nav-item d-flex align-items-center">
                                <Link href="/profil" class="nav-link px-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-person-circle"></i> {{ $page.props.auth.user.name }}
                                </Link>
                            </li>
                            <li class="nav-item d-flex align-items-center">
                                <Link href="/logout" method="post" as="button" class="nav-link px-2"
                                    @click.prevent="$inertia.post('/logout')">Déconnexion</Link>
                            </li>
                        </template>
                        <template v-else>
                            <li class="nav-item d-flex align-items-center gap-1">
                                <Link href="/login" class="nav-link px-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-box-arrow-in-right"></i> Connexion
                                </Link>
                            </li>
                            <li class="nav-item d-flex align-items-center">
                                <Link href="/register" class="btn btn-sm btn-outline-light ms-1">S'inscrire</Link>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="flex-grow-1">
            <slot />
        </main>

        <Footer />

        <ConsentBanner />
    </div>
</template>

<style>
.navbar-abc { background: #14532d; }

/* Liens en blanc pur : le rgba(255,255,255,.55) par défaut de
   navbar-dark manque de contraste sur ce fond vert foncé. */
.navbar-abc .nav-link {
    color: #ffffff;
}
.navbar-abc .nav-link:hover,
.navbar-abc .nav-link:focus {
    color: #d1fae5;
}
/* Style actif distinct : pastille semi-transparente + gras. */
.navbar-abc .nav-link.active {
    color: #ffffff;
    font-weight: 600;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 0.375rem;
}
</style>