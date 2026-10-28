import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            resolve: (name) =>
                resolvePageComponent(
                    `./Pages/${name}.vue`,
                    import.meta.glob('./Pages/**/*.vue'),
                ),
            setup({ App, props, plugin }) {
                return createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(ZiggyVue);
            },
        }),
    // Le port doit suivre INERTIA_SSR_URL (variable SSR_PORT posée par
    // ssr:watchdog) : le défaut 13714 est partagé par toute application
    // Inertia de la machine et provoque des rendus croisés entre vhosts.
    { port: Number(process.env.SSR_PORT ?? 13714) },
);
