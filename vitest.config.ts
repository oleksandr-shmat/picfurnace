import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

// A separate, minimal config for the frontend tests: they check plain functions, so the Laravel,
// Inertia and Wayfinder plugins of vite.config.ts (which also runs PHP) are not needed.
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
