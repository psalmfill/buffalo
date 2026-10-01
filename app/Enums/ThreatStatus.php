<?php

namespace App\Enums;

/**
 * Status of a threat report (Phase 6B). Only active threats are matched
 * against new incidents and expire automatically.
 */
enum ThreatStatus: string
{
    case Active = 'active';
    case Mitigated = 'mitigated';
    case Materialised = 'materialised';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Mitigated => 'Mitigated',
            self::Materialised => 'Materialised',
            self::Expired => 'Expired',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * Statuses an officer may set by hand (expiry is automatic).
     *
     * @return list<self>
     */
    public static function settable(): array
    {
        return [self::Mitigated, self::Materialised, self::Withdrawn];
    }

    public function canReactivate(): bool
    {
        return in_array($this, [self::Expired, self::Mitigated, self::Withdrawn], true);
    }
}
