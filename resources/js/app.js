import './bootstrap';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Suivi Matomo des navigations SPA (le traceur est chargé par défaut, sauf
// refus — RGPD, voir ConsentBanner.vue).
router.on('navigate', (event) => {
    if (!window._paq) {
        return;
    }
    window._paq.push(['setCustomUrl', event.detail.page.url]);
    window._paq.push(['setDocumentTitle', event.detail.page.props.site?.title ?? document.title]);
    window._paq.push(['trackPageView']);
});

createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#198754',
    },
});
