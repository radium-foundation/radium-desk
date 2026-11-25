import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/vitest-setup.js'],
        fileParallelism: false,
    },
});
