<?php

namespace App\Enums;

/**
 * What a cross-jurisdiction grant shows (Phase 3C). `full` includes
 * everything in `profile`.
 */
enum GrantScope: string
{
    case Profile = 'profile';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Profile => 'Profile',
            self::Full => 'Full record',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Profile => 'Name, other names, gender, date of birth or age, nationality, occupation, description, current photo, aliases and roles.',
            self::Full => 'Everything in the profile, plus NIN, phone number, address, person of interest details and history, political offices, group memberships and associations.',
        };
    }

    public function includes(self $other): bool
    {
        return $this === self::Full || $other === self::Profile;
    }
}
