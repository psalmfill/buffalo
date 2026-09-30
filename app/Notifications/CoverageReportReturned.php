<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A coverage report was returned for changes (Phase 4A): to its submitter.
 * Payloads carry no report content (BuffaloNotification): only the place,
 * the period and a link, which is access-checked when opened.
 */
final class CoverageReportReturned extends BuffaloNotification
{
    public function __construct(
        public readonly string $reportUlid,
        public readonly string $lga,
        public readonly string $period,
    ) {}

    public function type(): string
    {
        return 'coverage_report_returned';
    }

    public function title(User $notifiable): string
    {
        return 'Coverage report returned';
    }

    public function body(User $notifiable): string
    {
        return "The coverage report of {$this->lga} LGA for {$this->period} was returned for changes. Open it to see the reason.";
    }

    public function url(User $notifiable): string
    {
        return '/coverage-reports/'.$this->reportUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
