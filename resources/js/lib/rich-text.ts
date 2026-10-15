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
