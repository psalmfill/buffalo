import { useEffect, useState } from 'react';

function parts(ms: number): string {
    const minutes = Math.floor(Math.abs(ms) / 60_000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const mins = minutes % 60;

    return days > 0 ? `${days}d ${hours}h` : `${hours}h ${mins}m`;
}

/** A live countdown to an instant, refreshed every 30 seconds. */
export default function ExpiryCountdown({ at }: { at: string }) {
    const [now, setNow] = useState<number | null>(null);

    useEffect(() => {
        const tick = () => setNow(Date.now());
        const first = window.setTimeout(tick, 0);
        const timer = window.setInterval(tick, 30_000);

        return () => {
            window.clearTimeout(first);
            window.clearInterval(timer);
        };
    }, []);

    if (now === null) {
        return <span>…</span>;
    }

    const left = new Date(at).getTime() - now;

    return (
        <span
            className={
                left < 24 * 3600_000
                    ? 'font-semibold text-red-700 dark:text-red-400'
                    : ''
            }
            data-test="expiry-countdown"
        >
            {left > 0 ? `${parts(left)} left` : `expired ${parts(left)} ago`}
        </span>
    );
}
