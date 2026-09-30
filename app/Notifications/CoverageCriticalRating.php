<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A coverage report rated critical was submitted (Phase 4A): to the DG and
 * the DDGs covering the state, with critical severity.
 * Payloads carry no report content (BuffaloNotification): only the place,
 * the period and a link, which is access-checked when opened.
 */
final class CoverageCriticalRating extends BuffaloNotification
{
    public function __construct(
        public readonly string $reportUlid,
        public readonly string $lga,
        public readonly string $state,
        public readonly string $period,
    ) {}

    public function type(): string
    {
        return 'coverage_critical_rating';
    }

    public function title(User $notifiable): string
    {
        return 'Critical security coverage reported';
    }

    public function body(User $notifiable): string
    {
        return "{$this->lga} LGA, {$this->state} State rated its security coverage for {$this->period} as critical.";
    }

    public function url(User $notifiable): string
    {
        return '/coverage-reports/'.$this->reportUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Critical;
    }
}
