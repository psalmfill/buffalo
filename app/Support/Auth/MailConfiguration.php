<?php

namespace App\Support\Auth;

/**
 * Whether BUFFALO sends email at all (BUFFALO_MAIL_ENABLED). Deployments
 * without a mail server leave it off: setup links are then handed over
 * manually and notifications stay in the application.
 */
final class MailConfiguration
{
    public static function isConfigured(): bool
    {
        return (bool) config('buffalo.mail_enabled', false);
    }
}
