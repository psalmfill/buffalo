<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An officer passed the daily soft limit of cross-jurisdiction hits
 * (Phase 3C). Sent once per officer per day to the DG and the DDGs
 * covering the officer's state.
 */
final class MatchSoftLimitExceeded extends BuffaloNotification
{
    public function __construct(
        public readonly string $officer,
        public readonly string $officerState,
        public readonly int $limit,
    ) {}

    public function type(): string
    {
        return 'match_soft_limit';
    }

    public function title(User $notifiable): string
    {
        return 'High number of cross-jurisdiction hits';
    }

    public function body(User $notifiable): string
    {
        return "{$this->officer} ({$this->officerState}) has been shown more than {$this->limit} cross-jurisdiction hits today.";
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
