import { configureEcho } from '@laravel/echo-react';

type ChannelAuthorizationParams = { socketId: string; channelName: string };
type ChannelAuthorizationCallback = (
    error: Error | null,
    authData: { auth: string; channel_data?: string } | null,
) => void;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

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
