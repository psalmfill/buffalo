<?php

namespace App\Enums;

/**
 * What happened to the people counted in a casualty row (Phase 5A).
 */
enum CasualtyOutcome: string
{
    case Killed = 'killed';
    case Injured = 'injured';
    case Abducted = 'abducted';
    case Missing = 'missing';
    case Rescued = 'rescued';
    case Arrested = 'arrested';

    public function label(): string
    {
        return match ($this) {
            self::Killed => 'Killed',
            self::Injured => 'Injured',
            self::Abducted => 'Abducted',
            self::Missing => 'Missing',
            self::Rescued => 'Rescued',
            self::Arrested => 'Arrested',
        };
    }
}
