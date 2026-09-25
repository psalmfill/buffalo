<?php

namespace App\Support\Auth;

/**
 * Session keys used by the BUFFALO session policy.
 */
final class SessionKeys
{
    public const VERSION = 'buffalo.session_version';

    public const TOKEN = 'buffalo.session_token';

    public const STARTED_AT = 'buffalo.session_started_at';

    public const SHOW_RECOVERY_CODES = 'buffalo.show_recovery_codes';
}
