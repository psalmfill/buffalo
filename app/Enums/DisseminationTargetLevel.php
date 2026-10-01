<?php

namespace App\Enums;

/**
 * What a dissemination targets (Phase 7A): a whole state (its State
 * Director and Deputy) or one LGA (its chief, plus the State Director and
 * Deputy of that LGA's state).
 */
enum DisseminationTargetLevel: string
{
    case State = 'state';
    case Lga = 'lga';

    public function label(): string
    {
        return match ($this) {
            self::State => 'State',
            self::Lga => 'LGA',
        };
    }
}
