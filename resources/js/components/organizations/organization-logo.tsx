import { cn } from '@/lib/utils';
import type { LogoUrls } from '@/types/organizations';

/** Words left out of initials ("Bank of Industry" → "BI"). */
const FILLER = new Set(['of', 'and', 'the', 'for']);

/** Up to two initials from an organization's name. */
export function initials(name: string): string {
    const all = name
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .split(/\s+/)
        .filter((w) => w !== '');
    const significant = all.filter((w) => !FILLER.has(w.toLowerCase()));
    const words = significant.length > 0 ? significant : all;

    return words
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

const SIZES = {
    xs: 'size-6 text-[0.6rem]',
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-16 text-lg',
} as const;

/**
 * An organization's logo (Phase 11D), or a neutral placeholder with its
 * initials when it has none. The image comes from an authorised route.
 */
export default function OrganizationLogo({
    name,
    logo,
    size = 'sm',
    full = false,
    className,
}: {
    name: string;
    logo: LogoUrls | null;
    size?: keyof typeof SIZES;
    /** Use the full-size logo rather than the 96 px thumbnail. */
    full?: boolean;
    className?: string;
}) {
    const box = cn(
        'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted',
        SIZES[size],
        className,
    );

    if (logo) {
        return (
            <span className={cn(box, 'bg-white')}>
                <img
                    src={full ? logo.full : logo.thumb}
                    alt={`${name} logo`}
                    className="size-full object-contain"
                    loading="lazy"
                />
            </span>
        );
    }

    return (
        <span
            className={cn(box, 'font-semibold text-muted-foreground')}
            aria-hidden
            data-test="logo-placeholder"
        >
            {initials(name)}
        </span>
    );
}
