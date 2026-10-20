<?php

namespace App\Enums;

/**
 * Where an arrest stands (Phase 11C): for the arrest as a whole and for
 * each arrested person.
 */
enum ArrestStatus: string
{
    case InCustody = 'in_custody';
    case Charged = 'charged';
    case Arraigned = 'arraigned';
    case ReleasedOnBail = 'released_on_bail';
    case ReleasedWithoutCharge = 'released_without_charge';
    case Transferred = 'transferred';
    case Convicted = 'convicted';
    case Acquitted = 'acquitted';

    public function label(): string
    {
        return match ($this) {
            self::InCustody => 'In custody',
            self::Charged => 'Charged',
            self::Arraigned => 'Arraigned',
            self::ReleasedOnBail => 'Released on bail',
            self::ReleasedWithoutCharge => 'Released without charge',
            self::Transferred => 'Transferred',
            self::Convicted => 'Convicted',
            self::Acquitted => 'Acquitted',
        };
    }
}
