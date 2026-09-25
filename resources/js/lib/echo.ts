import { configureEcho } from '@laravel/echo-react';

/**
 * Laravel Echo client for Reverb.
 *
 * Browsers connect through nginx (same host and port as the app), which
 * proxies /app/* to the reverb container. Host, port and scheme come from the
 * VITE_REVERB_* variables in .env.
 *
 * Only native WebSocket transports are enabled: pusher-js's fallback
 * transports would load scripts from js.pusher.com, and usage stats (sent to
 * stats.pusher.com) stay disabled. BUFFALO makes no requests to external hosts.
 */
export function initializeEcho(): void {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 443);

    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
