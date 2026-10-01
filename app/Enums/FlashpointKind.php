<?php

namespace App\Enums;

/**
 * A flashpoint is an area of recurring tension that may erupt (e.g. a
 * disputed farmland); a blackspot is an area where incidents keep
 * happening (e.g. a kidnapping corridor). Phase 6A.
 */
enum FlashpointKind: string
{
    case Flashpoint = 'flashpoint';
    case Blackspot = 'blackspot';

    public function label(): string
    {
        return match ($this) {
            self::Flashpoint => 'Flashpoint',
            self::Blackspot => 'Blackspot',
        };
    }
}
