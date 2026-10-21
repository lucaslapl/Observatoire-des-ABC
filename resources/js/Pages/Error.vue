<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
    status: {
        type: Number,
        default: 404,
    },
    url: {
        type: String,
        default: '',
    },
});

const page = usePage();

const base = {
    403: {
        icon: 'bi-shield-lock',
        title: 'Accès refusé',
        message: 'Cette page nécessite des droits supplémentaires ou une connexion à votre compte.',
    },
    404: {
        icon: 'bi-search',
        title: 'Page introuvable',
        message: 'La page que vous cherchez n\'existe pas ou a été déplacée.',
    },
    419: {
        icon: 'bi-arrow-clockwise',
        title: 'Session expirée',
        message: 'Votre session a expiré depuis trop longtemps. Rechargez la page pour continuer.',
    },
    429: {
        icon: 'bi-hourglass-split',
        title: 'Trop de tentatives',
        message: 'Vous avez effectué un trop grand nombre de requêtes. Patientez un instant avant de réessayer.',
    },
    500: {
        icon: 'bi-bug',
        title: 'Erreur inattendue',
        message: 'Une erreur inattendue est survenue de notre côté. Réessayez dans un instant.',
    },
};

const content = computed(() => {
    const generic = base[props.status] ?? {
        icon: 'bi-exclamation-triangle',
        title: 'Une erreur est survenue',
        message: 'Une erreur inattendue est survenue. Réessayez dans un instant.',
        primary: { label: 'Retour à l\'accueil', href: '/', icon: 'bi-house' },
    };

    if (props.status !== 404) {
        return {
            ...generic,
            primary: props.status === 419 || props.status === 500
                ? null
                : (generic.primary ?? { label: 'Retour à l\'accueil', href: '/', icon: 'bi-house' }),
        };
    }

    const url = props.url || '';

    if (url.startsWith('/abc/')) {
        return {
            ...base[404],
            message: 'Le projet ABC que vous cherchez n\'existe pas ou a été retiré de l\'observatoire.',
            primary: { label: 'Rechercher sur la carte', href: '/carte', icon: 'bi-map' },
        };
    }

    if (url.startsWith('/actualites/')) {
        return {
            ...base[404],
            message: 'Cette actualité n\'existe plus ou a été déplacée.',
            primary: { label: 'Voir les actualités', href: '/actualites', icon: 'bi-newspaper' },
        };
    }

    if (url.startsWith('/commune/') || url.startsWith('/departement/') || url.startsWith('/region/')) {
        return {
            ...base[404],
            message: 'Cette commune, ce département ou cette région n\'a pas été trouvé(e) dans l\'observatoire.',
            primary: { label: 'Explorer la carte', href: '/carte', icon: 'bi-map' },
        };
    }

    return {
        ...base[404],
        primary: { label: 'Retour à l\'accueil', href: '/', icon: 'bi-house' },
    };
});

const secondary = computed(() => {
    switch (props.status) {
        case 403:
            return page.props.auth?.user
                ? { label: 'Carte des ABC', href: '/carte', icon: 'bi-map' }
                : { label: 'Se connecter', href: '/login', icon: 'bi-box-arrow-in-right' };
        case 404:
            return content.value.primary?.href === '/carte'
                ? { label: 'Retour à l\'accueil', href: '/', icon: 'bi-house' }
                : { label: 'Carte des ABC', href: '/carte', icon: 'bi-map' };
        case 419:
        case 500:
            return { label: 'Retour à l\'accueil', href: '/', icon: 'bi-house' };
        default:
            return null;
    }
});

const seoTitle = computed(() => `${content.value.title} — Observatoire des ABC`);
</script>

<template>
    <Layout>
        <Seo noindex :title="seoTitle" :description="content.message" />
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-7 text-center">
                    <p class="display-5 fw-bold text-success mb-1">{{ status }}</p>
                    <p class="fs-1 text-success mb-2"><i :class="['bi', content.icon]"></i></p>
                    <h1 class="h4 mb-3">{{ content.title }}</h1>
                    <p class="text-body-secondary mb-4" style="max-width: 32rem; margin-inline: auto;">
                        {{ content.message }}
                    </p>
                    <div class="mb-3">
                        <Link v-if="content.primary" :href="content.primary.href" class="btn btn-success me-2">
                            <i :class="['bi', content.primary.icon, 'me-1']"></i>{{ content.primary.label }}
                        </Link>
                        <a v-else-if="status === 419 || status === 500" :href="url || '/'" class="btn btn-success me-2">
                            <i class="bi bi-arrow-clockwise me-1"></i>Recharger la page
                        </a>
                        <Link v-if="secondary" :href="secondary.href" class="btn btn-outline-secondary">
                            <i :class="['bi', secondary.icon, 'me-1']"></i>{{ secondary.label }}
                        </Link>
                    </div>
                    <p class="mb-0 small">
                        <Link href="/contact" class="text-decoration-none">
                            <i class="bi bi-envelope me-1"></i>Un problème persiste ? Contactez-nous
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </Layout>
</template>
