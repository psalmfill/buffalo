<?php

namespace App\Support\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's password reset mail with a generic BUFFALO subject (Phase 8B.5).
 * Mail only; it is not a BuffaloNotification (no in-app copy, no
 * broadcast), so it lives here. Queued (Phase 9A): mail leaves only from the
 * queue workers, so the web process never needs a route to the mail relay.
 */
final class PasswordResetNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return parent::buildMailMessage($url)->subject(config('app.name').': password reset');
    }
}
