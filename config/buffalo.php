<?php

return [

    // Send email at all? Off for deployments without a mail server: setup
    // links are then handed over manually and notifications stay in-app.
    'mail_enabled' => (bool) env('BUFFALO_MAIL_ENABLED', false),

    // Optional banner at the top of every page, e.g. "RESTRICTED — Authorised
    // users only. All activity is logged." Level: info, restricted,
    // confidential or secret (sets the colour). No text, no banner.
    'banner' => [
        'text' => env('BUFFALO_BANNER_TEXT'),
        'level' => env('BUFFALO_BANNER_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication and sessions (Phase 1C)
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Sign out other sessions when an account signs in elsewhere.
        'single_session' => (bool) env('BUFFALO_SINGLE_SESSION', true),

        // Absolute maximum session age, whatever the activity. The idle
        // timeout is SESSION_LIFETIME (minutes).
        'session_max_hours' => (int) env('BUFFALO_SESSION_MAX_HOURS', 12),

        'lockout' => [
            // Consecutive failed attempts (password or 2FA code) before a lock.
            'max_attempts' => 5,
            // First lock; each further lock within the window doubles it.
            'base_minutes' => 15,
            'max_minutes' => 24 * 60,
            'window_hours' => 24,
        ],
    ],

];
