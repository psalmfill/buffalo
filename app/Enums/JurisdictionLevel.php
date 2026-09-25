<?php

namespace App\Enums;

/**
 * The level of the hierarchy a user's jurisdiction is attached to.
 *
 * - system: no geographic jurisdiction (system administration only)
 * - national: the whole country (optionally narrowed to zones for a DDG)
 * - state: one state (users.state_id)
 * - lga: one LGA (users.lga_id, and its state in users.state_id)
 */
enum JurisdictionLevel: string
{
    case System = 'system';
    case National = 'national';
    case State = 'state';
    case Lga = 'lga';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::National => 'National',
            self::State => 'State',
            self::Lga => 'LGA',
        };
    }
}
