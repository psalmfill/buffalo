<?php

namespace App\Support\RichText;

/**
 * A sanitised rich text value (Phase 11B): the HTML to store and display,
 * and its plain text copy for search, exports, notifications and limits.
 */
final readonly class RichTextValue
{
    public function __construct(
        public string $html,
        public string $text,
    ) {}

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }
}
