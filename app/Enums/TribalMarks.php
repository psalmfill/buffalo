<?php

namespace App\Enums;

/** The whether a person of interest has tribal marks (Phase 11G.4): an identification aid. */
enum TribalMarks: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
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
