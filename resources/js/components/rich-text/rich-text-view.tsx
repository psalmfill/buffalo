import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The markup the server allows (App\Support\RichText\RichText::ALLOWED). */
const ALLOWED = new Set([
    'p',
    'h3',
    'h4',
    'strong',
    'em',
    'u',
    'ul',
    'ol',
    'li',
    'blockquote',
    'a',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
    'br',
]);

function safeHref(href: string | null): string | null {
    if (!href) {
        return null;
    }

    try {
        const url = new URL(href, window.location.origin);

        return ['http:', 'https:', 'mailto:'].includes(url.protocol)
            ? href
            : null;
    } catch {
        return null;
    }
}

function isExternal(href: string): boolean {
    try {
        const url = new URL(href, window.location.origin);

        return (
            url.protocol !== 'mailto:' && url.origin !== window.location.origin
        );
    } catch {
        return false;
    }
}

/**
 * Builds React elements from the stored HTML, keeping only allowed
 * elements and the link's href: no innerHTML, so nothing outside the
 * allow-list can reach the page even if a stored value were tampered with.
 */
function render(node: Node, key: string): ReactNode {
    if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent;
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return null;
    }

    const element = node as Element;
    const name = element.nodeName.toLowerCase();
    const children = Array.from(element.childNodes).map((child, i) =>
        render(child, `${key}.${i}`),
    );

    if (!ALLOWED.has(name)) {
        // Unknown markup: its text only (scripts and styles never render).
        return ['script', 'style', 'template'].includes(name) ? null : (
            <span key={key}>{children}</span>
        );
    }

    if (name === 'br') {
        return <br key={key} />;
    }

    if (name === 'a') {
        const href = safeHref(element.getAttribute('href'));

        if (href === null) {
            return <span key={key}>{children}</span>;
        }

        const external = isExternal(href);

        return (
            <a
                key={key}
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                className="underline"
            >
                {children}
                {external && (
                    <>
                        <ExternalLink
                            className="ml-0.5 inline size-3 align-baseline"
                            aria-hidden
                        />
                        <span className="sr-only"> (external link)</span>
                    </>
                )}
            </a>
        );
    }

    const Tag = name as keyof React.JSX.IntrinsicElements;

    return <Tag key={key}>{children}</Tag>;
}

/**
 * Stored rich text (Phase 11B), already sanitised on the server, shown
 * safely. Links open in a new tab with rel="noopener noreferrer";
 * external links are marked. Plain text (`format="text"`: append-only
 * rows written before 11B) is shown as before, line breaks kept.
 */
export default function RichTextView({
    html,
    format = 'html',
    className,
    empty = null,
}: {
    html: string | null | undefined;
    format?: 'html' | 'text';
    className?: string;
    empty?: ReactNode;
}) {
    if (!html) {
        return <>{empty}</>;
    }

    if (format === 'text') {
        return <p className={cn('whitespace-pre-line', className)}>{html}</p>;
    }

    const body = new DOMParser().parseFromString(html, 'text/html').body;

    return (
        <div className={cn('rich-content', className)} data-rich-text>
            {Array.from(body.childNodes).map((child, i) =>
                render(child, String(i)),
            )}
        </div>
    );
}
