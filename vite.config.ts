import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

// The dev server runs in the `vite` container and is reached by the browser
// on the host at http://localhost:<port>.
const devServerPort = Number(process.env.VITE_DEV_SERVER_PORT ?? 5173);
const devServerOrigin = `http://localhost:${devServerPort}`;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
            // In Docker the Node container has no PHP; WAYFINDER_COMMAND=true
            // turns generation into a no-op and `make wayfinder` runs it in
            // the app container instead.
            command: process.env.WAYFINDER_COMMAND || undefined,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: devServerPort,
        strictPort: true,
        origin: devServerOrigin,
        cors: {
            origin: [
                /^http:\/\/localhost(:\d+)?$/,
                /^http:\/\/127\.0\.0\.1(:\d+)?$/,
            ],
        },
        hmr: {
            host: 'localhost',
            clientPort: devServerPort,
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
});
