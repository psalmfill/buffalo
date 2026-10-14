/**
 * WCAG contrast (Phase 11A.3), the same arithmetic as the server's
 * App\Support\Theme\Contrast, for the Theme page's live checks. The
 * server decides; this only shows the result while editing.
 */

function rgb(hex: string): [number, number, number] {
    return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as [
        number,
        number,
        number,
    ];
}

function luminance(hex: string): number {
    const [r, g, b] = rgb(hex).map((value) => {
        const c = value / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function isHex(value: string): boolean {
    return /^#[0-9a-fA-F]{6}$/.test(value);
}

export function contrastRatio(foreground: string, background: string): number {
    const a = luminance(foreground);
    const b = luminance(background);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function toHsl(hex: string): [number, number, number] {
    const [r, g, b] = rgb(hex).map((c) => c / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const d = max - min;

    if (d === 0) {
        return [0, 0, l];
    }

    const s = d / (1 - Math.abs(2 * l - 1));
    let h =
        max === r
            ? 60 * (((g - b) / d) % 6)
            : max === g
              ? 60 * ((b - r) / d + 2)
              : 60 * ((r - g) / d + 4);

    if (h < 0) {
        h += 360;
    }

    return [h, s, l];
}

function fromHsl(h: number, s: number, l: number): string {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    const [r, g, b] =
        h < 60
            ? [c, x, 0]
            : h < 120
              ? [x, c, 0]
              : h < 180
                ? [0, c, x]
                : h < 240
                  ? [0, x, c]
                  : h < 300
                    ? [x, 0, c]
                    : [c, 0, x];
    const hex = (v: number) =>
        Math.round((v + m) * 255)
            .toString(16)
            .padStart(2, '0');

    return `#${hex(r)}${hex(g)}${hex(b)}`;
}

/** The passing colour nearest in lightness, or null. */
export function suggestColour(
    colour: string,
    background: string,
    minimum: number,
): string | null {
    if (contrastRatio(colour, background) >= minimum) {
        return colour.toLowerCase();
    }

    const [h, s, l] = toHsl(colour);

    for (let step = 1; step <= 100; step++) {
        for (const candidate of [l - step / 100, l + step / 100]) {
            if (candidate < 0 || candidate > 1) {
                continue;
            }

            const hex = fromHsl(h, s, candidate);

            if (contrastRatio(hex, background) >= minimum) {
                return hex;
            }
        }
    }

    return null;
}
