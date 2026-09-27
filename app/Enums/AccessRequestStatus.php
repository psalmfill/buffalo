<?php

namespace App\Enums;

/**
 * Status of a cross-jurisdiction access request (Phase 3C). Pending and
 * escalated requests are open; the others are final.
 */
enum AccessRequestStatus: string
{
    case Pending = 'pending';
    case Escalated = 'escalated';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Escalated => 'Escalated',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Escalated;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Escalated->value];
    }
}
