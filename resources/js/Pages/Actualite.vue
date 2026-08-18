<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';
import Breadcrumbs from '../Components/Breadcrumbs.vue';

const props = defineProps({
    actualite: { type: Object, required: true },
});

const page = usePage();
const site = computed(() => page.props.site || {});

const a = computed(() => props.actualite);

const formatDate = (d) =>
    d ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(d)) : '';

const crumbs = computed(() => [
    { label: 'Actualités', url: '/actualites' },
    { label: a.value.titre },
]);

const jsonLd = computed(() => {
    const base = site.value.url || '';
    return [
        {
            '@context': 'https://schema.org',
            '@type': 'BreadcrumbList',
            itemListElement: [
                { '@type': 'ListItem', position: 1, name: 'Accueil', item: base + '/' },
                { '@type': 'ListItem', position: 2, name: 'Actualités', item: `${base}/actualites` },
                { '@type': 'ListItem', position: 3, name: a.value.titre },
            ],
        },
        {
            '@context': 'https://schema.org',
            '@type': 'NewsArticle',
            headline: a.value.titre,
            datePublished: a.value.date_publication,
            dateModified: a.value.date_publication,
            image: site.value.ogImage,
            publisher: {
                '@type': 'Organization',
                name: site.value.name,
                logo: { '@type': 'ImageObject', url: `${base}/favicon.svg` },
            },
            ...(a.value.auteur ? { author: { '@type': 'Person', name: a.value.auteur } } : { author: { '@type': 'Organization', name: site.value.name } }),
        },
    ];
});
</script>

<template>
    <Layout>
        <Seo :title="a.titre" :description="a.contenu?.slice(0, 160)" :jsonLd="jsonLd" />
        <div class="container py-4">
            <article style="max-width: 760px;">
                <Breadcrumbs :items="crumbs" />
                <h1 class="h3 mb-2">{{ a.titre }}</h1>
                <p class="text-muted small mb-4">
                    {{ formatDate(a.date_publication) }}
                    <template v-if="a.auteur"> · {{ a.auteur }}</template>
                </p>
                <div class="fs-6" style="white-space: pre-wrap;">{{ a.contenu }}</div>
                <p class="mt-4"><Link class="btn btn-outline-success btn-sm" href="/actualites">← Toutes les actualités</Link></p>
            </article>
        </div>
    </Layout>
</template>
