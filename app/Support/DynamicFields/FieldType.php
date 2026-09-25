<?php

namespace App\Support\DynamicFields;

/**
 * Types a dynamic (incident type detail) field may have.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Select = 'select';
    case Multiselect = 'multiselect';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Number;
    }

    public function isText(): bool
    {
        return $this === self::Text || $this === self::Textarea;
    }

    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::Multiselect;
    }

    /** Longest value allowed; a field's max_length may lower it, not raise it. */
    public function maxLengthLimit(): ?int
    {
        return match ($this) {
            self::Text => 255,
            self::Textarea => 5000,
            default => null,
        };
    }
}
