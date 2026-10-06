<?php

namespace App\Support\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's password reset mail with a generic BUFFALO subject (Phase 8B.5).
 * Mail only and sent at once, like Laravel's own; it is not a
 * BuffaloNotification (no in-app copy, no broadcast), so it lives here.
 */
final class PasswordResetNotification extends ResetPassword
{
    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return parent::buildMailMessage($url)->subject(config('app.name').': password reset');
    }
}
