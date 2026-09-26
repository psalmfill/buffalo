export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: {
            message?: string;
            errors?: Record<string, string[]>;
        },
    ) {
        super(body.message ?? `Request failed (${status})`);
    }
}

/**
 * Same-origin JSON POST with the session's CSRF cookie. For responses that
 * must not pass through Inertia (e.g. a one-time setup link).
 */
export async function postJson<T>(url: string, body: unknown = {}): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new HttpError(response.status, data);
    }

    return data as T;
}

/** Same-origin JSON GET (for small reference lookups; nothing sensitive in the URL). */
export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new HttpError(response.status, data);
    }

    return data as T;
}
