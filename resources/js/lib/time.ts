const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/**
 * "5 minutes ago", "yesterday", ... Formatted locally (no external data).
 */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** A date-only value ("2026-10-31") as "31 Oct 2026", without time zone shifts. */
export function formatDate(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const [year, month, day] = date.slice(0, 10).split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'en-GB',
        { dateStyle: 'medium', timeZone: 'UTC' },
    );
}
