<?php

namespace App\Enums;

/** The hair colour of a person of interest (Phase 11G.4). */
enum HairColour: string
{
    case Black = 'black';
    case Brown = 'brown';
    case Grey = 'grey';
    case White = 'white';
    case Dyed = 'dyed';
    case Bald = 'bald';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Black => 'Black',
            self::Brown => 'Brown',
            self::Grey => 'Grey',
            self::White => 'White',
            self::Dyed => 'Dyed',
            self::Bald => 'Bald',
            self::Other => 'Other',
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
