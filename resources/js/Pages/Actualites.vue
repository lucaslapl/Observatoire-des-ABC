<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

const props = defineProps({
    actualites: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (d) =>
    d ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(d)) : '';

// L'actualité la plus récente est mise en avant, les suivantes en grille.
const aLaUne = computed(() => props.actualites[0] ?? null);
const autres = computed(() => props.actualites.slice(1));
</script>

<template>
    <Layout>
        <Seo
            title="Actualités"
            description="Actualités et nouveautés de l'Observatoire des Atlas de la Biodiversité Communale (ABC)."
        />
        <header class="actualites-header">
            <div class="container py-5">
                <h1><i class="bi bi-newspaper me-2" aria-hidden="true"></i>Actualités</h1>
                <p class="lead mb-2">
                    Nouveautés du site, avancées des projets ABC et coulisses de la
                    vérification des données de l'Observatoire.
                </p>
                <a class="rss-link" href="/feed.xml">
                    <i class="bi bi-rss-fill me-1" aria-hidden="true"></i>S'abonner au flux RSS
                </a>
            </div>
        </header>

        <div class="container py-5">
            <div v-if="actualites.length === 0" class="empty-state text-center">
                <i class="bi bi-newspaper" aria-hidden="true"></i>
                <p class="mb-0">Aucune actualité publiée pour le moment — revenez bientôt.</p>
            </div>

            <template v-else>
                <article v-if="aLaUne" class="actu-une">
                    <p class="actu-date mb-2">
                        <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>{{ formatDate(aLaUne.date_publication) }}
                    </p>
                    <h2 class="actu-titre mb-2">
                        <Link :href="`/actualites/${aLaUne.slug}`">{{ aLaUne.titre }}</Link>
                    </h2>
                    <p class="actu-extrait mb-3">{{ aLaUne.extrait }}</p>
                    <Link class="btn btn-success btn-sm" :href="`/actualites/${aLaUne.slug}`">
                        Lire l'article<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </Link>
                </article>

                <div class="row g-4 mt-0">
                    <article v-for="a in autres" :key="a.id" class="col-md-6">
                        <Link class="actu-card" :href="`/actualites/${a.slug}`">
                            <p class="actu-date mb-1">
                                <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>{{ formatDate(a.date_publication) }}
                            </p>
                            <h2 class="actu-titre h6 mb-2">{{ a.titre }}</h2>
                            <p class="actu-extrait mb-2">{{ a.extrait }}</p>
                            <span class="actu-lire">
                                Lire l'article<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                            </span>
                        </Link>
                    </article>
                </div>
            </template>
        </div>
    </Layout>
</template>

<style scoped>
.actualites-header {
    background: #f0fdf4;
    border-bottom: 1px solid #bbf7d0;
}
.actualites-header h1 {
    color: #14532d;
    font-weight: 700;
    font-size: 1.9rem;
    margin-bottom: 0.5rem;
}
.actualites-header .lead {
    color: #475569;
    font-size: 1rem;
    max-width: 640px;
}
.rss-link {
    display: inline-block;
    margin-top: 0.75rem;
    font-size: 0.85rem;
    color: #166534;
    text-decoration: none;
}
.rss-link:hover { text-decoration: underline; }

.empty-state {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 3rem 1.5rem;
    color: #64748b;
}
.empty-state .bi { display: block; font-size: 2rem; color: #94a3b8; margin-bottom: 0.75rem; }

.actu-une {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-left: 4px solid #2d6a4f;
    border-radius: 10px;
    padding: 1.5rem 1.75rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.actu-une .actu-titre { font-size: 1.4rem; }
.actu-une .actu-titre a { color: #14532d; text-decoration: none; }
.actu-une .actu-titre a:hover { text-decoration: underline; }
.actu-une .actu-extrait { color: #475569; max-width: 720px; }

.actu-date {
    font-size: 0.8rem;
    color: #689f38;
    font-variant-numeric: tabular-nums;
}

.actu-card {
    display: block;
    height: 100%;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    text-decoration: none;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}
.actu-card:hover {
    border-color: #86efac;
    box-shadow: 0 2px 8px rgba(20, 83, 45, 0.1);
}
.actu-card .actu-titre { color: #14532d; font-weight: 600; }
.actu-extrait {
    font-size: 0.9rem;
    color: #64748b;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.actu-lire {
    font-size: 0.85rem;
    font-weight: 600;
    color: #166534;
}
</style>
