<?php

return [

    // Send email at all? Off for deployments without a mail server: setup
    // links are then handed over manually and notifications stay in-app.
    'mail_enabled' => (bool) env('BUFFALO_MAIL_ENABLED', false),

    // Optional banner at the top of every page, e.g. "RESTRICTED — Authorised
    // users only. All activity is logged." Level: info, restricted,
    // confidential or secret (sets the colour). No text, no banner.
    // Weekly reminder to LGA chiefs whose LGA profile has not been reviewed
    // for this many days (Phase 2B).
    'profile_review_days' => (int) env('BUFFALO_PROFILE_REVIEW_DAYS', 180),

    // Persons of interest are due for review this many months after being
    // designated or last reviewed (Phase 3B). A cleared POI is due in
    // `poi_cleared_review_days` days, to decide on archiving.
    'poi_review_months' => (int) env('BUFFALO_POI_REVIEW_MONTHS', 12),
    'poi_cleared_review_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Cross-jurisdiction matching, requests and grants (Phase 3C)
    |--------------------------------------------------------------------------
    */
    'cross_jurisdiction' => [
        // Trigram similarity of name or alias. strong: at least this AND
        // the same date of birth. possible: at least this (never shown
        // across jurisdictions).
        'strong_similarity' => (float) env('BUFFALO_MATCH_STRONG_SIMILARITY', 0.75),
        'possible_similarity' => (float) env('BUFFALO_MATCH_POSSIBLE_SIMILARITY', 0.6),
        'max_hits' => 10,
        // Hits shown per user per day: above the soft limit the DG and the
        // DDGs covering the user's state are told (once a day); at the hard
        // limit no further hits are shown that day.
        'soft_daily_limit' => (int) env('BUFFALO_MATCH_SOFT_LIMIT', 20),
        'hard_daily_limit' => (int) env('BUFFALO_MATCH_HARD_LIMIT', 50),
        // Lifetime of a hit token (request access within this time).
        'token_minutes' => 120,
        // Pending requests escalate after this many minutes.
        'escalate_urgent_minutes' => (int) env('BUFFALO_ESCALATE_URGENT_MINUTES', 120),
        'escalate_normal_minutes' => (int) env('BUFFALO_ESCALATE_NORMAL_MINUTES', 48 * 60),
        // Escalated requests expire after this many days.
        'expire_escalated_days' => 7,
        // Grant holders are reminded this many hours before expiry.
        'grant_reminder_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security coverage reports (Phase 4A)
    |--------------------------------------------------------------------------
    */
    'coverage' => [
        // The report each LGA is expected to submit: weekly, monthly or
        // quarterly. Reminders go out once the grace days after the last
        // completed period have passed.
        'expected_period' => env('BUFFALO_COVERAGE_PERIOD', 'monthly'),
        'grace_days' => (int) env('BUFFALO_COVERAGE_GRACE_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incident workflow (Phase 5C)
    |--------------------------------------------------------------------------
    | Minutes after submission within which an incident should be verified,
    | by severity. Past the deadline the SD and DSD are reminded once; at
    | twice the deadline the DG and the DDGs covering the state are told.
    */
    'incidents' => [
        'verify_within_minutes' => [
            'critical' => (int) env('BUFFALO_VERIFY_CRITICAL_MINUTES', 60),
            'high' => (int) env('BUFFALO_VERIFY_HIGH_MINUTES', 360),
            'medium' => (int) env('BUFFALO_VERIFY_MEDIUM_MINUTES', 1440),
            'low' => (int) env('BUFFALO_VERIFY_LOW_MINUTES', 2880),
        ],
        // The incident map shows at most this many points (Phase 5D).
        'map_cap' => (int) env('BUFFALO_INCIDENT_MAP_CAP', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incident evidence (Phase 5B)
    |--------------------------------------------------------------------------
    | Limits in megabytes per evidence type. Keep them within nginx's
    | client_max_body_size and PHP's upload_max_filesize (100M, docker/).
    */
    'evidence' => [
        'max_mb' => [
            'image' => (int) env('BUFFALO_EVIDENCE_IMAGE_MB', 25),
            'document' => (int) env('BUFFALO_EVIDENCE_DOCUMENT_MB', 25),
            'video' => (int) env('BUFFALO_EVIDENCE_VIDEO_MB', 100),
            'audio' => (int) env('BUFFALO_EVIDENCE_AUDIO_MB', 50),
        ],
        // Files larger than this are verified and processed on the queue;
        // the item shows "processing" until then.
        'queue_above_mb' => (int) env('BUFFALO_EVIDENCE_QUEUE_ABOVE_MB', 10),
        // Image previews (longest side, pixels). EXIF is never kept.
        'thumb_px' => 300,
        'preview_px' => 1600,
    ],

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
