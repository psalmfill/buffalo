<?php

namespace App\Enums;

/**
 * Status of a person's membership of a criminal group (Phase 3B).
 */
enum MembershipStatus: string
{
    case Suspected = 'suspected';
    case Confirmed = 'confirmed';
    case Former = 'former';

    public function label(): string
    {
        return match ($this) {
            self::Suspected => 'Suspected',
            self::Confirmed => 'Confirmed',
            self::Former => 'Former',
        };
    }
}
