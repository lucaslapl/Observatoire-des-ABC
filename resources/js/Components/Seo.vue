<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({
    title: { type: String, default: null },
    description: { type: String, default: null },
    canonical: { type: String, default: null },
    robots: { type: String, default: null },
    ogType: { type: String, default: null },
    ogImage: { type: String, default: null },
    jsonLd: { type: [Object, Array], default: null },
    noindex: { type: Boolean, default: false },
});

const page = usePage();
const site = computed(() => page.props.site || {});
const baseUrl = computed(() => site.value.url || '');

function toAbsolute(value) {
    if (!value) return null;
    if (/^https?:\/\//i.test(value)) return value;
    try {
        return new URL(value, baseUrl.value).href;
    } catch {
        return value;
    }
}

const pageTitle = computed(() => {
    if (props.title) return `${props.title} — ${site.value.name || 'Observatoire des ABC'}`;
    return site.value.title || site.value.name || 'Observatoire des ABC';
});

const pageDescription = computed(
    () => props.description || site.value.description || '',
);

const canonical = computed(() => {
    if (props.canonical) return toAbsolute(props.canonical);
    try {
        const url = new URL(page.url, baseUrl.value);
        url.search = '';
        url.hash = '';
        return url.href;
    } catch {
        return null;
    }
});

const robots = computed(() => {
    if (props.robots) return props.robots;
    return props.noindex ? 'noindex, follow' : 'index, follow';
});

const ogType = computed(() => props.ogType || site.value.og_type || 'website');
const ogImage = computed(() =>
    toAbsolute(props.ogImage || site.value.ogImage || '/og-image.png'),
);

const ldBlocks = computed(() => {
    if (!props.jsonLd) return [];
    return Array.isArray(props.jsonLd) ? props.jsonLd : [props.jsonLd];
});

const ldJson = (block) =>
    JSON.stringify(block).replace(/</g, '\\u003c');
</script>

<template>
    <Head>
        <title>{{ pageTitle }}</title>
        <meta name="description" :content="pageDescription" v-if="pageDescription" />
        <meta name="robots" :content="robots" />
        <link rel="canonical" :href="canonical" v-if="canonical" />
        <meta property="og:site_name" :content="site.name" />
        <meta property="og:title" :content="pageTitle" />
        <meta property="og:description" :content="pageDescription" v-if="pageDescription" />
        <meta property="og:type" :content="ogType" />
        <meta property="og:url" :content="canonical" v-if="canonical" />
        <meta property="og:locale" content="fr_FR" />
        <meta property="og:image" :content="ogImage" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" :content="pageTitle" />
        <meta name="twitter:description" :content="pageDescription" />
        <meta name="twitter:image" :content="ogImage" />
        <template v-for="block in ldBlocks" :key="JSON.stringify(block)">
            <script v-html="ldJson(block)" type="application/ld+json"></script>
        </template>
    </Head>
</template>
