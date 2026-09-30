<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An LGA's coverage report for the last completed period is missing after
 * the grace days (Phase 4A): to the LGA's security chief, once per period.
 * Payloads carry no report content (BuffaloNotification): only the place,
 * the period and a link, which is access-checked when opened.
 */
final class CoverageReportMissing extends BuffaloNotification
{
    public function __construct(
        public readonly string $lga,
        public readonly string $period,
    ) {}

    public function type(): string
    {
        return 'coverage_report_missing';
    }

    public function title(User $notifiable): string
    {
        return 'Coverage report overdue';
    }

    public function body(User $notifiable): string
    {
        return "No coverage report has been submitted for {$this->lga} LGA for {$this->period}.";
    }

    public function url(User $notifiable): string
    {
        return '/coverage-reports/create';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
