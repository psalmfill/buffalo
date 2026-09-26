<?php

namespace App\Enums;

/**
 * How two persons are associated (person_associations, Phase 3A).
 *
 * A row (person, associated person, relationship) reads "the associated
 * person is the person's <relationship>". Seen from the associated person,
 * directional relationships invert (employer <-> employee).
 */
enum AssociationRelationship: string
{
    case Family = 'family';
    case Associate = 'associate';
    case Accomplice = 'accomplice';
    case Employer = 'employer';
    case Employee = 'employee';
    case PoliticalAlly = 'political_ally';
    case Rival = 'rival';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Family => 'Family',
            self::Associate => 'Associate',
            self::Accomplice => 'Accomplice',
            self::Employer => 'Employer',
            self::Employee => 'Employee',
            self::PoliticalAlly => 'Political ally',
            self::Rival => 'Rival',
            self::Other => 'Other',
        };
    }

    /** The same relationship seen from the other person. */
    public function inverse(): self
    {
        return match ($this) {
            self::Employer => self::Employee,
            self::Employee => self::Employer,
            default => $this,
        };
    }
}
