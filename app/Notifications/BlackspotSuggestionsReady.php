<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Daily (Phase 6C): new suggested blackspots in a state, for its State
 * Director. Counts only.
 */
final class BlackspotSuggestionsReady extends BuffaloNotification
{
    public function __construct(
        public readonly string $state,
        public readonly int $count,
    ) {}

    public function type(): string
    {
        return 'blackspot_suggestions';
    }

    public function title(User $notifiable): string
    {
        return 'New suggested blackspots';
    }

    public function body(User $notifiable): string
    {
        $what = $this->count === 1 ? '1 new suggested blackspot' : "{$this->count} new suggested blackspots";

        return "{$what} in {$this->state} State, from clusters of recent incidents. Review and accept or dismiss them.";
    }

    public function url(User $notifiable): string
    {
        return '/blackspot-suggestions';
    }
}
