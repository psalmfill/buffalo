<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Daily (Phase 3C): how many of the state's records produced hits for
 * officers elsewhere in the last 24 hours, by requesting state and
 * officer. The records themselves are listed only on the linked page,
 * which the owner can open because the records are theirs.
 */
final class MatchDigest extends BuffaloNotification
{
    /**
     * @param  list<string>  $lines  e.g. "Lagos State: 3 hits (Tunde Lagos Chief 2, …)"
     */
    public function __construct(
        public readonly string $state,
        public readonly int $total,
        public readonly array $lines,
    ) {}

    public function type(): string
    {
        return 'match_digest';
    }

    public function title(User $notifiable): string
    {
        return 'Cross-jurisdiction searches of your records';
    }

    public function body(User $notifiable): string
    {
        return "{$this->total} ".($this->total === 1 ? 'hit' : 'hits')." on {$this->state} records in the last 24 hours. ".implode('; ', $this->lines).'.';
    }

    public function url(User $notifiable): string
    {
        return '/access-requests?tab=activity';
    }
}
