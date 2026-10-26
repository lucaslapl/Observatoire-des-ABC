<script setup>
import { Link } from '@inertiajs/vue3';
import { reopenConsentChoice } from '../utils/consent';

// Pied de page partagé : utilisé par Layout.vue (toutes les pages) ET par
// Map.vue (l'accueil, qui n'utilise pas Layout). Toute nouvelle page publique
// doit être liée ici — et seulement ici.
const colonnes = [
    {
        titre: 'Explorer',
        liens: [
            { label: 'Carte des ABC', href: '/carte' },
            { label: 'Actualités', href: '/actualites' },
        ],
    },
    {
        titre: 'Comprendre',
        liens: [
            { label: 'À propos', href: '/a-propos' },
            { label: 'Méthodologie des données', href: '/methodologie' },
        ],
    },
    {
        titre: 'Réutiliser',
        liens: [
            { label: 'API & réutilisation', href: '/api' },
            // Page HTML servie hors Inertia : navigation complète, pas de <Link>.
            { label: 'Documentation API', href: '/api/doc', externe: true },
        ],
    },
    {
        titre: 'Informations',
        liens: [
            { label: 'Contact', href: '/contact' },
            { label: 'Mentions légales', href: '/mentions-legales' },
            { label: 'Confidentialité', href: '/confidentialite' },
            { label: 'Cookies', action: 'consent' },
        ],
    },
];
</script>

<template>
    <footer class="site-footer py-4 mt-auto">
        <div class="container">
            <div class="row gy-4">
                <div class="col-12 col-lg-4">
                    <div class="footer-brand h5 mb-2">Observatoire des ABC</div>
                    <p class="small mb-0 footer-lead">
                        Suivi ouvert des projets Atlas de la Biodiversité
                        Communale, enrichi par les vérifications et les
                        contributions de ses contributeurs.
                    </p>
                </div>
                <div v-for="col in colonnes" :key="col.titre" class="col-6 col-lg-2">
                    <h2 class="footer-title">{{ col.titre }}</h2>
                    <ul class="list-unstyled small mb-0">
                        <li v-for="lien in col.liens" :key="lien.label" class="mb-1">
                            <a
                                v-if="lien.action === 'consent'"
                                href="#"
                                @click.prevent="reopenConsentChoice"
                            >{{ lien.label }}</a>
                            <a v-else-if="lien.externe" :href="lien.href">{{ lien.label }}</a>
                            <Link v-else :href="lien.href">{{ lien.label }}</Link>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom small text-center mt-4 pt-3">
                <div class="mb-1">
                    Données réutilisées en lisant
                    <a href="https://data.gouv.fr" rel="noopener">data.gouv.fr</a>. Projet ouvert.
                </div>
                <div>
                    Développé par
                    <a href="https://lucaslaplanche.fr" target="_blank" rel="noopener">Lucas LAPLANCHE</a>
                    · Code source sur
                    <a href="https://github.com/lucaslapl/Observatoire-des-ABC" target="_blank" rel="noopener">GitHub</a>
                </div>
            </div>
        </div>
    </footer>
</template>

<style scoped>
.site-footer { background: #14532d; color: #e8f5e9; }
.site-footer a { color: #fff; }
.site-footer a:hover { color: #f0fdf4; }
.footer-brand { color: #fff; }
.footer-lead { color: #e8f5e9; }
.footer-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #a5d6a7;
}
.footer-bottom { border-top: 1px solid rgba(255, 255, 255, 0.25); }
</style>
