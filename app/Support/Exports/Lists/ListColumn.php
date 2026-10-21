<?php

namespace App\Support\Exports\Lists;

use Closure;

/**
 * One exportable column of a list (Phase 11E): a heading and how to read
 * its value from a row. Values are plain text, numbers or null; rich text
 * fields are exported through their plain text copies (`*_text`).
 */
final readonly class ListColumn
{
    /**
     * @param  Closure(mixed): (string|int|float|bool|null)  $value
     */
    public function __construct(
        public string $key,
        public string $label,
        public Closure $value,
    ) {}

    /**
     * @param  Closure(mixed): (string|int|float|bool|null)  $value
     */
    public static function make(string $key, string $label, Closure $value): self
    {
        return new self($key, $label, $value);
    }

    public function read(mixed $row): string|int|float|null
    {
        $value = ($this->value)($row);

        return is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
    }
}
