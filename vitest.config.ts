import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Frontend unit tests (pure TypeScript logic, Node environment). Kept apart
// from vite.config.ts so the Laravel, Inertia and Wayfinder plugins do not
// run. Wayfinder helpers must be generated first (`make wayfinder`).
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
});
