import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/customer-portal.js', 'resources/js/service-case-show.js'],
            refresh: true,
        }),
    ],
});
