<?php

namespace App\Enums;

/**
 * How a person is involved in an incident (Phase 5A).
 */
enum PersonInvolvement: string
{
    case Victim = 'victim';
    case Suspect = 'suspect';
    case Witness = 'witness';
    case Informant = 'informant';
    case Complainant = 'complainant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Victim => 'Victim',
            self::Suspect => 'Suspect',
            self::Witness => 'Witness',
            self::Informant => 'Informant',
            self::Complainant => 'Complainant',
            self::Other => 'Other',
        };
    }
}
