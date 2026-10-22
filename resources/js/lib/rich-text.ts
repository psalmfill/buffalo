/**
 * Rich text helpers (Phase 11B). plainText() mirrors the server's
 * App\Support\RichText\RichText::plainText(), so the editor's character
 * counter counts what the server validates: block elements become line
 * breaks (a blank line between paragraphs), list items start with "- ",
 * table cells are separated by " | ".
 */

const BLOCKS = new Set(['p', 'h3', 'h4', 'blockquote', 'table', 'ul', 'ol']);

function text(node: Node, depth: number): string {
    let out = '';

    node.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            const value = (child.textContent ?? '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ');
            // No leading space on a new line.
            out +=
                out === '' || out.endsWith('\n')
                    ? value.replace(/^ +/, '')
                    : value;

            return;
        }

        if (child.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const name = child.nodeName.toLowerCase();
        const inner = text(
            child,
            name === 'ul' || name === 'ol' ? depth + 1 : depth,
        );

        if (name === 'br') {
            out += '\n';
        } else if (name === 'li') {
            out +=
                inner.trim() === ''
                    ? ''
                    : `\n${'  '.repeat(Math.max(0, depth - 1))}- ${inner.trim().replace(/\n{2,}/g, '\n')}\n`;
        } else if (name === 'tr') {
            out += `\n${inner.trim().replace(/^[ |]+|[ |]+$/g, '')}\n`;
        } else if (name === 'th' || name === 'td') {
            out += `${inner.trim()} | `;
        } else if (name === 'ul' || name === 'ol' || name === 'table') {
            out += `\n\n${inner.replace(/\n{2,}/g, '\n').replace(/^\n+|\n+$/g, '')}\n\n`;
        } else if (BLOCKS.has(name)) {
            out += `\n\n${inner.replace(/^ +| +$/g, '')}\n\n`;
        } else {
            out += inner;
        }
    });

    return out;
}

/** The plain text of rich text HTML. */
export function plainText(html: string): string {
    if (html.trim() === '') {
        return '';
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    return text(doc.body, 0)
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/** Whether rich text has no text at all (formatting alone is empty). */
export function isEmptyRichText(html: string | null | undefined): boolean {
    return !html || plainText(html) === '';
}

/** Elements dropped from pasted HTML before the editor parses it. */
const PASTE_DROP =
    'script, style, link, meta, img, picture, svg, iframe, object, embed, video, audio, source, form, input, button, template';

/**
 * Cleans pasted HTML in an inert document (DOMParser: no scripting, no
 * loading) before the editor's own parser sees it: event-handler
 * attributes, inline styles and active or embedded elements are removed.
 * The editor's schema keeps only the allow-listed formatting anyway, and
 * the server sanitises every save; this keeps the browser from even
 * parsing a pasted inline handler (found by the Phase 11 browser check:
 * an `onerror` in pasted Word or web content raised a CSP violation).
 */
export function cleanPastedHtml(html: string): string {
    // Chrome reports a CSP violation as soon as any document, even an inert
    // DOMParser one, parses an inline handler attribute: drop them from the
    // tags before parsing (the parse below catches anything left).
    const withoutHandlers = html.replace(/<[^>]*>/g, (tag) =>
        tag.replace(
            /\s+on[a-z0-9_:-]*\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi,
            '',
        ),
    );
    const doc = new DOMParser().parseFromString(withoutHandlers, 'text/html');

    doc.body.querySelectorAll(PASTE_DROP).forEach((el) => el.remove());
    doc.body.querySelectorAll('*').forEach((el) => {
        for (const attr of Array.from(el.attributes)) {
            const name = attr.name.toLowerCase();

            if (
                name.startsWith('on') ||
                name === 'style' ||
                name === 'srcset' ||
                (name === 'href' &&
                    !/^(https?:|mailto:|#|\/)/i.test(attr.value.trim()))
            ) {
                el.removeAttribute(attr.name);
            }
        }
    });

    return doc.body.innerHTML;
}
