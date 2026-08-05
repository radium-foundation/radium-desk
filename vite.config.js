import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/customer-portal.js',
                'resources/js/pages/dashboard.js',
                'resources/js/pages/platform.js',
                'resources/js/pages/system-settings.js',
                'resources/js/pages/application-settings.js',
                'resources/js/pages/mission-control.js',
                'resources/js/pages/orders.js',
                'resources/js/pages/service-cases.js',
                'resources/js/pages/refunds.js',
            ],
            refresh: true,
        }),
    ],
});
