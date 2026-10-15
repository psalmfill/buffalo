<?php

namespace App\Models\Concerns;

use App\Casts\RichTextCast;

/**
 * Rich text fields (Phase 11B): each is cast through RichTextCast (always
 * sanitised; plain text copy in `<field>_text`). The copies are never sent
 * to the browser and never logged separately (the field's change is).
 *
 * Models list their fields in richTextFields().
 */
trait HasRichText
{
    /**
     * @return list<string>
     */
    abstract public function richTextFields(): array;

    public function initializeHasRichText(): void
    {
        $this->mergeCasts(array_fill_keys($this->richTextFields(), RichTextCast::class));
        $this->makeHidden($this->richTextCopies());
    }

    /**
     * @return list<string>
     */
    public function richTextCopies(): array
    {
        return array_map(fn (string $field) => "{$field}_text", $this->richTextFields());
    }
}
