<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An officer's search matched a record above their clearance (Phase 3C):
 * no hit was shown to them. Sent to the owning State Director
 * (confidential) or the DG and DDGs (secret). Names the officer and their
 * state; never the record.
 */
final class SuppressedMatchAlert extends BuffaloNotification
{
    public function __construct(
        public readonly string $officer,
        public readonly string $officerState,
        public readonly string $level,
    ) {}

    public function type(): string
    {
        return 'suppressed_match';
    }

    public function title(User $notifiable): string
    {
        return 'Search matched a record above the officer\'s clearance';
    }

    public function body(User $notifiable): string
    {
        return "{$this->officer} ({$this->officerState}) searched and matched a {$this->level} record above their clearance. No hit was shown to them.";
    }

    public function url(User $notifiable): string
    {
        return '/access-requests?tab=oversight';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
