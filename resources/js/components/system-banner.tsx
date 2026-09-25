import { usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import type { BannerLevel } from '@/types/app';

/** The banner's fixed height; layouts offset fixed elements by it. */
export const BANNER_HEIGHT = '1.75rem';

// Solid colours with the same contrast in light and dark mode.
const LEVEL_STYLES: Record<BannerLevel, string> = {
    info: 'bg-slate-700 text-white',
    restricted: 'bg-amber-300 text-amber-950',
    confidential: 'bg-blue-700 text-white',
    secret: 'bg-red-700 text-white',
};

/**
 * Optional full-width banner at the very top of every page
 * (BUFFALO_BANNER_TEXT / BUFFALO_BANNER_LEVEL).
 */
export function SystemBanner() {
    const { banner } = usePage().props.app;

    if (banner === null) {
        return null;
    }

    return (
        <div
            role="note"
            aria-label="System banner"
            title={banner.text}
            data-test="system-banner"
            data-level={banner.level}
            style={{ height: BANNER_HEIGHT }}
            className={cn(
                'sticky top-0 z-50 flex w-full items-center justify-center px-4 text-center text-xs font-semibold tracking-wide',
                LEVEL_STYLES[banner.level],
            )}
        >
            <span className="truncate">{banner.text}</span>
        </div>
    );
}

/**
 * Sets --banner-h for layouts with fixed elements (the sidebar), so they
 * start below the banner.
 */
export function useBannerOffset(): CSSProperties {
    const { banner } = usePage().props.app;

    return {
        '--banner-h': banner === null ? '0px' : BANNER_HEIGHT,
    } as CSSProperties;
}
