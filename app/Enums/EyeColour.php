<?php

namespace App\Enums;

/** The eye colour of a person of interest (Phase 11G.4). */
enum EyeColour: string
{
    case Black = 'black';
    case DarkBrown = 'dark_brown';
    case Brown = 'brown';
    case Hazel = 'hazel';
    case Grey = 'grey';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Black => 'Black',
            self::DarkBrown => 'Dark brown',
            self::Brown => 'Brown',
            self::Hazel => 'Hazel',
            self::Grey => 'Grey',
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
