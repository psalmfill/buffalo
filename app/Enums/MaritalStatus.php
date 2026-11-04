<?php

namespace App\Enums;

/** The marital status of a person of interest (Phase 11G.4). */
enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Separated = 'separated';
    case Widowed = 'widowed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Married => 'Married',
            self::Divorced => 'Divorced',
            self::Separated => 'Separated',
            self::Widowed => 'Widowed',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
