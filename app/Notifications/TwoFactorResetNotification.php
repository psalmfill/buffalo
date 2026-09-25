<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

class TwoFactorResetNotification extends BuffaloNotification
{
    public function type(): string
    {
        return 'two_factor_reset';
    }

    public function title(User $notifiable): string
    {
        return 'Two-factor authentication was reset';
    }

    public function body(User $notifiable): string
    {
        return 'An administrator reset two-factor authentication on your account and signed you out everywhere. You will set it up again at your next sign-in. If you did not ask for this, contact your administrator immediately.';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Warning;
    }

    public function allowsMail(): bool
    {
        return true;
    }
}
