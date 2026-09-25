<?php

namespace App\Support\Auth;

/**
 * Whether outgoing email can actually be delivered. Deployments without a
 * mail server use the `log` (or `array`) mailer; nothing is sent then.
 */
final class MailConfiguration
{
    public static function isConfigured(): bool
    {
        $mailer = config('mail.default');

        return is_string($mailer) && ! in_array($mailer, ['log', 'array'], true);
    }
}
