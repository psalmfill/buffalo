<?php

namespace App\Notifications;

use App\Models\ReportRun;
use App\Models\User;

/**
 * A scheduled report is ready (Phase 7D): "Your [report name] is ready"
 * with a link to "My reports". It may also go by email, but the file is
 * never attached; it is downloaded only after signing in.
 */
final class ReportReady extends BuffaloNotification
{
    public function __construct(public readonly ReportRun $run) {}

    public function type(): string
    {
        return 'report_ready';
    }

    public function title(User $notifiable): string
    {
        return 'Your '.mb_strtolower($this->run->report_type->label()).' is ready';
    }

    public function body(User $notifiable): string
    {
        return 'Your '.mb_strtolower($this->run->report_type->label()).' is ready. Sign in to download it.';
    }

    public function url(User $notifiable): string
    {
        return '/reports?run='.$this->run->ulid;
    }

    public function allowsMail(): bool
    {
        return true;
    }
}
