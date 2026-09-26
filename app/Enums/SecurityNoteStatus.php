<?php

namespace App\Enums;

/**
 * Status of a community security note (Phase 2C).
 */
enum SecurityNoteStatus: string
{
    case Active = 'active';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Monitoring => 'Monitoring',
            self::Resolved => 'Resolved',
        };
    }
}
