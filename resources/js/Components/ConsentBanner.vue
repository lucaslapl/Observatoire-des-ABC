<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const tracking = page.props.site?.tracking || {};
const STORAGE_KEY = 'abc-consent';
const open = ref(false);

function showIfNeeded() {
    if (typeof window === 'undefined' || !hasTracking() || localStorage.getItem(STORAGE_KEY)) {
        return;
    }
    open.value = true;
}

function hasTracking() {
    return Boolean(tracking.matomo_url || tracking.gtag_id);
}

function choose(ok) {
    if (typeof window === 'undefined' || !hasTracking()) {
        return;
    }
    localStorage.setItem(STORAGE_KEY, ok ? 'accepted' : 'refused');
    if (ok) {
        loadTracking();
    }
    open.value = false;
}

function loadTracking() {
    if (tracking.matomo_url && tracking.matomo_site_id) {
        const url = tracking.matomo_url.replace(/\/$/, '');
        window._paq = window._paq || [];
        window._paq.push(['setTrackerUrl', url + '/matomo.php']);
        window._paq.push(['setSiteId', String(tracking.matomo_site_id)]);
        window._paq.push(['trackPageView']);
        window._paq.push(['enableLinkTracking']);
        const s = document.createElement('script');
        s.async = true; s.defer = true;
        s.src = url + '/matomo.js';
        document.head.appendChild(s);
    } else if (tracking.gtag_id) {
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        window.gtag('js', new Date());
        window.gtag('config', tracking.gtag_id);
        const s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(tracking.gtag_id);
        document.head.appendChild(s);
    }
}

if (typeof window !== 'undefined') {
    document.addEventListener('DOMContentLoaded', showIfNeeded);
    showIfNeeded();
}
</script>

<template>
    <div v-if="open" class="consent-banner" role="dialog" aria-label="Consentement à la mesure d'audience">
        <div class="consent-body">
            <p>
                Ce site peut utiliser une mesure d'audience anonyme pour améliorer ses contenus.
                Aucun traceur n'est chargé sans votre accord. Plus de détails dans la
                <a href="/confidentialite">politique de confidentialité</a>.
            </p>
            <div class="consent-actions">
                <button type="button" class="btn btn-sm btn-success" @click="choose(true)">Accepter</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="choose(false)">Refuser</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.consent-banner {
    position: fixed; z-index: 1200; inset-inline: 0; bottom: 0;
    background: rgba(20, 40, 30, .97); color: #fff; padding: 12px 16px;
    box-shadow: 0 -3px 14px rgba(0, 0, 0, .35);
    display: flex; justify-content: center;
}
.consent-body { max-width: 900px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; font-size: .85rem; }
.consent-body p { margin: 0; flex: 1 1 280px; }
.consent-body a { color: #a7f3d0; }
.consent-actions { display: flex; gap: 8px; }
</style>
