<?php

namespace App\Enums;

/** Strength of a duplicate candidate pair (Phase 10A.3). */
enum DuplicateStrength: string
{
    case Certain = 'certain';
    case Strong = 'strong';
    case Possible = 'possible';

    public function label(): string
    {
        return match ($this) {
            self::Certain => 'Certain',
            self::Strong => 'Strong',
            self::Possible => 'Possible',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Certain => 3,
            self::Strong => 2,
            self::Possible => 1,
        };
    }
}
