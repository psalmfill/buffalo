<?php

namespace App\Enums;

/** Why a report schedule is paused (Phase 8B.5). */
enum SchedulePauseReason: string
{
    case Manual = 'manual';
    case PostingChange = 'posting_change';
    case Deactivated = 'deactivated';
    case OwnerBlocked = 'owner_blocked';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Paused by you',
            self::PostingChange => 'Paused because your role or jurisdiction changed. Check the report and place before resuming.',
            self::Deactivated => 'Paused because the account was deactivated',
            self::OwnerBlocked => 'Paused because the account could not run it',
        };
    }
}
