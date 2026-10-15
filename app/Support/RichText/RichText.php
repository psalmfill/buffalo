<?php

namespace App\Support\RichText;

use Dom\HTMLDocument;
use Dom\Node;
use LengthException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one place rich text is sanitised (Phase 11B), on the server, on
 * every save, whatever the browser sent.
 *
 * Allowed: p, h3, h4, strong, em, u, ul, ol, li, blockquote, br, simple
 * tables (table, thead, tbody, tr, th, td) and links (href only; http,
 * https, mailto or relative; rel="noopener noreferrer" and
 * target="_blank" are added). Everything else goes: every other
 * attribute (style, class, on*), and script, iframe, img, svg, form and
 * the like together with their content. Formatting wrappers pasted from
 * Word or web pages (span, div, font, b, i, …) are unwrapped, keeping
 * their text.
 *
 * Also produces the plain text copy: block elements become line breaks
 * (a blank line between paragraphs), list items start with "- ", table
 * cells are separated by " | ". The frontend's plainText() in
 * resources/js/lib/rich-text.ts mirrors this for the character counter.
 */
final class RichText
{
    public const ALLOWED = ['p', 'h3', 'h4', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br'];

    /** Unwrapped (children kept): harmless formatting from pasted content. */
    private const UNWRAPPED = ['span', 'div', 'font', 'b', 'i', 's', 'strike', 'del', 'ins', 'sub', 'sup', 'small', 'big', 'mark', 'code', 'pre', 'kbd', 'samp', 'tt', 'h1', 'h2', 'h5', 'h6', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'center', 'caption', 'tfoot', 'abbr', 'cite', 'dfn', 'q', 'time', 'address', 'figure', 'figcaption', 'label', 'dl', 'dt', 'dd', 'hgroup', 'details', 'summary'];

    /** Removed with their content (also the default for anything not listed). */
    private const DROPPED = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'img', 'picture', 'source', 'video', 'audio', 'track', 'canvas', 'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'template', 'noscript', 'link', 'meta', 'base', 'head', 'title', 'colgroup', 'col', 'map', 'area', 'portal', 'dialog'];

    private const BLOCKS = ['p', 'h3', 'h4', 'blockquote', 'table', 'ul', 'ol'];

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Sanitises HTML from the browser. Null, or HTML without any text,
     * gives null.
     *
     * @throws LengthException when longer than buffalo.rich_text.max_html_length
     */
    public static function sanitize(?string $html): ?RichTextValue
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        if (strlen($html) > self::maxHtmlLength()) {
            throw new LengthException('Rich text is longer than the allowed maximum.');
        }

        $clean = trim(self::sanitizer()->sanitize(self::normalise($html)));
        $text = self::plainText($clean);

        return $text === '' ? null : new RichTextValue($clean, $text);
    }

    /**
     * Plain text (existing records, CSV imports) as rich text: escaped,
     * paragraphs (blank lines) in <p>, single line breaks as <br>.
     */
    public static function fromPlainText(?string $text): ?RichTextValue
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $paragraphs = preg_split('/\R\s*\R/u', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [];
        $html = implode('', array_map(
            fn (string $p) => '<p>'.str_replace("\n", '<br>', htmlspecialchars(trim($p), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')).'</p>',
            array_filter($paragraphs, fn (string $p) => trim($p) !== ''),
        ));

        return self::sanitize($html);
    }

    /**
     * A stored value that may predate Phase 11B (e.g. a merge snapshot):
     * HTML is sanitised as it is, plain text is converted first.
     */
    public static function fromStored(?string $value): ?RichTextValue
    {
        if ($value === null) {
            return null;
        }

        return preg_match('#<(p|h3|h4|ul|ol|li|blockquote|table|br|strong|em|u|a)[\s/>]#i', $value) === 1
            ? self::sanitize($value)
            : self::fromPlainText($value);
    }

    /**
     * Two values combined (person merges, Phase 10A): one block per
     * source under a labelled heading. Either alone is kept as it is.
     */
    public static function combine(?string $ours, string $oursLabel, ?string $theirs, string $theirsLabel): ?RichTextValue
    {
        $a = self::sanitize($ours);
        $b = self::sanitize($theirs);

        if ($b === null || ($a !== null && $a->html === $b->html)) {
            return $a;
        }

        $heading = fn (string $label) => '<h4>'.htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</h4>';

        return self::sanitize(($a === null ? '' : $heading($oursLabel).$a->html).$heading($theirsLabel).$b->html);
    }

    /**
     * Stored rich text for output in Blade (PDFs): sanitised again on the
     * way out (defence in depth), so it is safe for {!! !!}.
     */
    public static function display(?string $html): string
    {
        return self::sanitize($html)->html ?? '';
    }

    /** The plain text of sanitised HTML. */
    public static function plainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
        $body = $document->body;
        $text = $body === null ? '' : self::text($body, 0);

        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function maxHtmlLength(): int
    {
        return (int) config('buffalo.rich_text.max_html_length');
    }

    /** The HTML length allowed for a field with this plain text limit. */
    public static function htmlLimit(int $plainLimit): int
    {
        return min(self::maxHtmlLength(), $plainLimit * (int) config('buffalo.rich_text.html_factor'));
    }

    /**
     * Pasted structure mapped onto the allowed elements before sanitising,
     * so it keeps its meaning: b/i to strong/em, h1-h2 to h3, h5-h6 to h4,
     * other block containers to paragraphs. Only tag names change here
     * (attributes are dropped); the sanitiser still decides what stays.
     */
    private static function normalise(string $html): string
    {
        $map = [
            'b' => 'strong', 'i' => 'em',
            'h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4',
            'div' => 'p', 'section' => 'p', 'article' => 'p', 'header' => 'p', 'footer' => 'p', 'main' => 'p', 'aside' => 'p',
            'nav' => 'p', 'pre' => 'p', 'address' => 'p', 'center' => 'p', 'figure' => 'p', 'figcaption' => 'p', 'dt' => 'p', 'dd' => 'p',
        ];

        return preg_replace_callback(
            '#<(/?)('.implode('|', array_keys($map)).')(?=[\s/>])[^>]*>#i',
            fn (array $m) => '<'.$m[1].$map[strtolower($m[2])].'>',
            $html,
        ) ?? $html;
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->withMaxInputLength(self::maxHtmlLength());

        foreach (self::ALLOWED as $element) {
            $config = $config->allowElement($element, $element === 'a' ? ['href'] : []);
        }
        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }
        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        $config = $config
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank');

        return self::$sanitizer = new HtmlSanitizer($config);
    }

    private static function text(Node $node, int $depth): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $child->textContent)) ?? '';
                // No leading space on a new line.
                $out .= $out === '' || str_ends_with($out, "\n") ? ltrim($text, ' ') : $text;

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $name = strtolower((string) $child->nodeName);
            $inner = self::text($child, in_array($name, ['ul', 'ol'], true) ? $depth + 1 : $depth);

            $out .= match (true) {
                $name === 'br' => "\n",
                // Editors wrap item text in <p>: no blank lines inside an item.
                $name === 'li' => trim($inner) === '' ? '' : "\n".str_repeat('  ', max(0, $depth - 1)).'- '.(preg_replace("/\n{2,}/u", "\n", trim($inner)) ?? '')."\n",
                $name === 'tr' => "\n".trim($inner, ' |')."\n",
                // One line per list item and per table row.
                in_array($name, ['ul', 'ol', 'table'], true) => "\n\n".trim(preg_replace("/\n{2,}/u", "\n", $inner) ?? '', "\n")."\n\n",
                $name === 'th', $name === 'td' => trim($inner).' | ',
                in_array($name, self::BLOCKS, true) => "\n\n".trim($inner, ' ')."\n\n",
                default => $inner,
            };
        }

        return $out;
    }
}
