// @vitest-environment jsdom
import { Editor } from '@tiptap/core';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { richTextExtensions } from '@/components/rich-text/extensions';
import RichTextView from '@/components/rich-text/rich-text-view';
import { isEmptyRichText, plainText } from '@/lib/rich-text';

const ALLOWED_TAGS = new Set([
    'div',
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
    'span',
    'svg',
    'path',
]);

function tags(markup: string): string[] {
    return Array.from(markup.matchAll(/<([a-z0-9]+)[\s>/]/gi), (m) =>
        m[1].toLowerCase(),
    );
}

describe('RichTextView (Phase 11B)', () => {
    it('renders only allowed markup, even from a tampered value', () => {
        const markup = renderToStaticMarkup(
            <RichTextView
                html={
                    '<p onclick="x()" style="color:red"><strong>Fact</strong></p>' +
                    '<img src=x onerror=alert(1)><script>alert(1)</script>' +
                    '<iframe src="https://e.com"></iframe><a href="javascript:alert(1)">bad</a>' +
                    '<form><input></form><style>p{}</style>'
                }
            />,
        );

        for (const tag of tags(markup)) {
            expect(ALLOWED_TAGS.has(tag)).toBe(true);
        }

        expect(markup).toContain('<strong>Fact</strong>');
        expect(markup).not.toMatch(
            /onclick|onerror|style=|javascript:|<script|<img|<iframe|<form|<input/,
        );
        // A link with an unsafe address keeps its text only.
        expect(markup).toContain('<span>bad</span>');
    });

    it('opens links in a new tab without an opener and marks external ones', () => {
        const markup = renderToStaticMarkup(
            <RichTextView
                html={
                    '<p><a href="https://example.org/x">site</a> <a href="/incidents">local</a> <a href="mailto:a@b.c">mail</a></p>'
                }
            />,
        );

        expect(markup).toContain(
            '<a href="https://example.org/x" target="_blank" rel="noopener noreferrer" class="underline">site',
        );
        expect(markup).toContain('(external link)');
        expect(
            markup.match(/\(external link\)/g)?.length,
            'only the off-site link is marked',
        ).toBe(1);
        expect(markup).toContain('href="/incidents" target="_blank"');
    });

    it('shows plain text rows as before, line breaks kept and nothing parsed', () => {
        const markup = renderToStaticMarkup(
            <RichTextView html={'Old <b>note</b>\nline two'} format="text" />,
        );

        expect(markup).toBe(
            '<p class="whitespace-pre-line">Old &lt;b&gt;note&lt;/b&gt;\nline two</p>',
        );
    });

    it('shows the empty placeholder when there is nothing', () => {
        expect(
            renderToStaticMarkup(<RichTextView html={null} empty="—" />),
        ).toBe('—');
    });
});

describe('plainText (mirrors the server)', () => {
    it('matches App\\Support\\RichText\\RichText::plainText', () => {
        expect(
            plainText(
                '<p>Intro <strong>bold</strong></p><ul><li><p>one</p></li><li><p>two</p><ul><li><p>nested</p></li></ul></li></ul><p>a<br>b</p>' +
                    '<table><tbody><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>',
            ),
        ).toBe(
            'Intro bold\n\n- one\n- two\n  - nested\n\na\nb\n\nA | B\n1 | 2',
        );
        expect(plainText('<p>Line one<br />Line two</p><p>x &amp; y</p>')).toBe(
            'Line one\nLine two\n\nx & y',
        );
    });

    it('treats formatting without text as empty', () => {
        expect(isEmptyRichText('<p></p><p><strong> </strong><br></p>')).toBe(
            true,
        );
        expect(isEmptyRichText('<p>x</p>')).toBe(false);
        expect(plainText('<ul><li></li></ul>')).toBe('');
    });
});

describe('the editor schema (Phase 11B)', () => {
    it('keeps bold and lists from pasted Word content but drops fonts, colours and images', () => {
        const editor = new Editor({
            extensions: richTextExtensions(),
            content:
                '<p class="MsoNormal" style="font-family:Calibri;color:#FF0000"><span style="font-size:14pt;color:red"><b>Suspects</b> seen at <i>dawn</i></span></p>' +
                '<ul style="list-style:square"><li><span style="background:yellow">Tall man</span></li></ul>' +
                '<p><img src="x.png" onerror="alert(1)"><font face="Arial" color="blue">plain</font> <code>code</code> <s>struck</s></p>' +
                '<h1>Big</h1><pre>pre</pre><hr><a href="javascript:alert(1)">bad</a> <a href="https://example.org">good</a>',
        });
        const html = editor.getHTML();
        editor.destroy();

        expect(html).toContain('<strong>Suspects</strong>');
        expect(html).toContain('<em>dawn</em>');
        expect(html).toContain('<ul><li><p>Tall man</p></li></ul>');
        expect(html).toContain(
            '<a target="_blank" rel="noopener noreferrer" href="https://example.org">good</a>',
        );
        expect(html).not.toMatch(
            /style=|class=|<img|<font|<span|<code|<s>|<h1|<pre|<hr|javascript:|color|Calibri/,
        );
    });
});
