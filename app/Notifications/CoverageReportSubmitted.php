<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A coverage report was submitted for review (Phase 4A): to the State
 * Director and Deputy State Director of the state.
 * Payloads carry no report content (BuffaloNotification): only the place,
 * the period and a link, which is access-checked when opened.
 */
final class CoverageReportSubmitted extends BuffaloNotification
{
    public function __construct(
        public readonly string $reportUlid,
        public readonly string $lga,
        public readonly string $period,
    ) {}

    public function type(): string
    {
        return 'coverage_report_submitted';
    }

    public function title(User $notifiable): string
    {
        return 'Coverage report submitted';
    }

    public function body(User $notifiable): string
    {
        return "The coverage report of {$this->lga} LGA for {$this->period} was submitted for review.";
    }

    public function url(User $notifiable): string
    {
        return '/coverage-reports/'.$this->reportUlid;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Info;
    }
}
