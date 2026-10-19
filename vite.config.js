import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            ssr: 'resources/js/ssr.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        // Dev Docker : Vite écoute sur 0.0.0.0 dans le conteneur, mais le
        // fichier public/hot doit pointer vers l'URL vue par le navigateur.
        // VITE_DEV_ORIGIN=http://localhost:5173 est défini dans docker-compose.
        origin: process.env.VITE_DEV_ORIGIN,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
