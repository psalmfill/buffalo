<?php

namespace App\Support\Help;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;

/**
 * Renders a manual to sanitised HTML (Phase 9B.4):
 *
 * - raw HTML in the Markdown is stripped, unsafe link schemes refused;
 * - images are removed (manuals describe screens in words);
 * - external links (any scheme, or //host) become plain text, so a manual
 *   can never make the browser fetch or open anything outside BUFFALO;
 * - links to other manuals (`evidence.md#section`, `../guides/evidence.md`)
 *   become /help/... links, and unknown ones plain text;
 * - headings (levels 2–3) get ids, for contextual help and the contents.
 */
final class MarkdownRenderer
{
    public function __construct(private readonly ManualLibrary $library) {}

    /**
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public function render(Manual $manual): array
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'heading_permalink' => [
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->sanitise($event->getDocument()), 100);

        $html = (string) (new MarkdownConverter($environment))->convert($manual->body);

        preg_match_all('#<h([23]) id="([^"]+)">(.*?)</h\1>#s', $html, $matches, PREG_SET_ORDER);
        $toc = array_map(fn (array $m) => [
            'id' => $m[2],
            'text' => html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5),
            'level' => (int) $m[1],
        ], $matches);

        return ['html' => $html, 'toc' => $toc];
    }

    private function sanitise(Document $document): void
    {
        $images = [];
        $links = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            } elseif ($node instanceof Link) {
                $links[] = $node;
            }
        }

        foreach ($images as $image) {
            $image->detach();
        }

        foreach ($links as $link) {
            $url = $link->getUrl();

            if (str_starts_with($url, '#')) {
                continue;
            }

            $internal = $this->internalUrl($url);
            if ($internal !== null) {
                $link->setUrl($internal);
                $link->setTitle(null);

                continue;
            }

            // External or unknown: keep the words, drop the link.
            foreach ($link->children() as $child) {
                $link->insertBefore($child);
            }
            if ($link->firstChild() === null && $link->previous() === null) {
                $link->insertBefore(new Text(''));
            }
            $link->detach();
        }
    }

    /** A link to another manual, as a /help URL; null when not one. */
    private function internalUrl(string $url): ?string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url) === 1) {
            return null;
        }

        [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $slug = basename((string) $path, '.md');

        foreach ($this->library->all() as $manual) {
            if ($manual->slug === $slug && $manual->kind !== 'cards') {
                return route('help.show', ['kind' => $manual->kind, 'slug' => $manual->slug], false)
                    .($fragment !== null && $fragment !== '' ? '#'.preg_replace('/[^a-z0-9-]/', '', strtolower($fragment)) : '');
            }
        }

        return null;
    }
}
