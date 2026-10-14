import { useEffect, useState } from 'react';

const SIZE = 8;

function read(): string[] {
    const style = getComputedStyle(document.documentElement);

    return Array.from({ length: SIZE }, (_, i) =>
        style.getPropertyValue(`--chart-palette-${i + 1}`).trim(),
    ).filter((colour) => colour !== '');
}

/**
 * The configured chart palette (Phase 11A.3), read from the CSS custom
 * properties the theme sets, so a change applies without a rebuild. Re-read
 * when light and dark mode switch.
 */
export function useChartPalette(): string[] {
    const [palette, setPalette] = useState<string[]>(() => read());

    useEffect(() => {
        const observer = new MutationObserver(() => setPalette(read()));
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    return palette;
}
