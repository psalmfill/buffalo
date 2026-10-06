<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * The user's report schedules were paused after a posting change (Phase
 * 8B.5). They review and resume them on the schedules page.
 */
final class ReportSchedulesPaused extends BuffaloNotification
{
    public function __construct(public readonly int $count) {}

    public function type(): string
    {
        return 'report_schedules_paused';
    }

    public function title(User $notifiable): string
    {
        return 'Your report schedules were paused';
    }

    public function body(User $notifiable): string
    {
        $schedules = $this->count === 1 ? '1 report schedule' : "{$this->count} report schedules";

        return "Your role or jurisdiction changed, so {$schedules} were paused. Review them and resume the ones you still need.";
    }

    public function url(User $notifiable): string
    {
        return '/reports/schedules';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }
}
