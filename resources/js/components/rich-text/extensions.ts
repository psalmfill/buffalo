import type { Extensions } from '@tiptap/core';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';

/**
 * The editor's schema (Phase 11B): exactly the server's allow-list.
 * Paragraphs, headings 3 and 4, bold, italic, underline, lists, quotes,
 * links (http, https, mailto) and simple tables. Anything else pasted
 * (images, colours, fonts, code) has no place in the schema and is
 * dropped by the editor; the server sanitises again regardless.
 */
export function richTextExtensions(placeholder = ''): Extensions {
    return [
        StarterKit.configure({
            heading: { levels: [3, 4] },
            code: false,
            codeBlock: false,
            horizontalRule: false,
            strike: false,
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                    target: '_blank',
                },
            },
        }),
        TableKit.configure({ table: { resizable: false } }),
        Placeholder.configure({ placeholder }),
    ];
}
