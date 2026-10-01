<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Daily at 07:00 (Phase 6B): routine threat reports recorded in the last 24
 * hours that the State Director or Deputy can see. References, threat
 * types and LGAs only.
 */
final class ThreatDigest extends BuffaloNotification
{
    /**
     * @param  list<string>  $lines  "THR/LA/2026/00012 — Kidnapping, Ikeja LGA"
     */
    public function __construct(
        public readonly int $count,
        public readonly array $lines,
    ) {}

    public function type(): string
    {
        return 'threat_digest';
    }

    public function title(User $notifiable): string
    {
        return 'Daily threat digest';
    }

    public function body(User $notifiable): string
    {
        $head = $this->count === 1 ? '1 routine threat report' : "{$this->count} routine threat reports";
        $more = $this->count > count($this->lines) ? ' …' : '';

        return "{$head} in the last 24 hours: ".implode('; ', $this->lines).$more;
    }

    public function url(User $notifiable): string
    {
        return '/threats?urgency=routine';
    }
}
