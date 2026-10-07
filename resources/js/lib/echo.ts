import { configureEcho } from '@laravel/echo-react';
import { xsrfToken } from '@/lib/http';

type ChannelAuthorizationParams = { socketId: string; channelName: string };
type ChannelAuthorizationCallback = (
    error: Error | null,
    authData: { auth: string; channel_data?: string } | null,
) => void;

/**
 * Authorises private channels (e.g. `users.{ulid}`) with the current
 * session's CSRF cookie, read at authorisation time because it changes when
 * the user signs in.
 */
async function authorizeChannel(
    params: ChannelAuthorizationParams,
    callback: ChannelAuthorizationCallback,
): Promise<void> {
    try {
        const response = await fetch('/broadcasting/auth', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                socket_id: params.socketId,
                channel_name: params.channelName,
            }),
        });

        if (!response.ok) {
            callback(
                new Error(`Channel authorisation failed (${response.status})`),
                null,
            );

            return;
        }

        callback(null, await response.json());
    } catch (error) {
        callback(
            error instanceof Error ? error : new Error(String(error)),
            null,
        );
    }
}

/**
 * Laravel Echo client for Reverb.
 *
 * Browsers connect through nginx (same host and port as the app), which
 * proxies /app/* to the reverb container. Configured at run time (Phase 9A),
 * so one build serves every installation: the public app key from the
 * page's <meta name="reverb-key">, and host, port and scheme from the page's
 * own address. The VITE_REVERB_* variables apply only to the development
 * server (Vite on another port).
 *
 * Only native WebSocket transports are enabled: pusher-js's fallback
 * transports would load scripts from js.pusher.com, and usage stats (sent to
 * stats.pusher.com) stay disabled. BUFFALO makes no requests to external hosts.
 */
export function initializeEcho(): void {
    const dev = import.meta.env.DEV;
    const https = dev
        ? (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https'
        : window.location.protocol === 'https:';
    const port = dev
        ? Number(import.meta.env.VITE_REVERB_PORT ?? 443)
        : Number(window.location.port || (https ? 443 : 80));
    const key =
        document
            .querySelector<HTMLMetaElement>('meta[name="reverb-key"]')
            ?.getAttribute('content') || import.meta.env.VITE_REVERB_APP_KEY;

    configureEcho({
        broadcaster: 'reverb',
        key,
        wsHost: dev
            ? (import.meta.env.VITE_REVERB_HOST ?? window.location.hostname)
            : window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: https,
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: '/broadcasting/auth',
            transport: 'ajax',
            customHandler: (
                params: ChannelAuthorizationParams,
                callback: ChannelAuthorizationCallback,
            ) => void authorizeChannel(params, callback),
        },
    });
}
