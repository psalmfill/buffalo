<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * LGAs of a state whose coverage report for the last completed period is
 * missing after the grace days (Phase 4A): to the State Director, once per
 * period. LGA names only.
 * Payloads carry no report content (BuffaloNotification): only the place,
 * the period and a link, which is access-checked when opened.
 */
final class CoverageReportsMissingSummary extends BuffaloNotification
{
    public function __construct(
        public readonly string $state,
        public readonly string $period,
        /** @var list<string> */
        public readonly array $lgas,
    ) {}

    public function type(): string
    {
        return 'coverage_reports_missing_summary';
    }

    public function title(User $notifiable): string
    {
        return 'Coverage reports overdue';
    }

    public function body(User $notifiable): string
    {
        return count($this->lgas)." LGA(s) of {$this->state} State have no coverage report for {$this->period}: ".implode(', ', $this->lgas).'.';
    }

    public function url(User $notifiable): string
    {
        return '/coverage-reports';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
