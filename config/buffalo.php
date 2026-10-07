<?php

// Retention in days from an environment variable; unset or empty: keep forever.
$retentionDays = fn (string $name): ?int => ($value = env($name)) === null || $value === '' ? null : (int) $value;

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
    | Flashpoints and blackspots (Phase 6A)
    |--------------------------------------------------------------------------
    | Days until the next review after each assessment, by concern level.
    | Incident suggestions look this many months back. A blackspot with
    | at least `escalation_incident_count` linked or nearby incidents within
    | `escalation_window_days` triggers one reassessment notice per window.
    */
    'flashpoints' => [
        'review_days' => [
            'severe' => (int) env('BUFFALO_FLASHPOINT_REVIEW_SEVERE_DAYS', 30),
            'high' => (int) env('BUFFALO_FLASHPOINT_REVIEW_HIGH_DAYS', 60),
            'moderate' => (int) env('BUFFALO_FLASHPOINT_REVIEW_MODERATE_DAYS', 90),
            'low' => (int) env('BUFFALO_FLASHPOINT_REVIEW_LOW_DAYS', 180),
        ],
        'suggestion_months' => (int) env('BUFFALO_FLASHPOINT_SUGGESTION_MONTHS', 12),
        'escalation_incident_count' => (int) env('BUFFALO_FLASHPOINT_ESCALATION_COUNT', 3),
        'escalation_window_days' => (int) env('BUFFALO_FLASHPOINT_ESCALATION_DAYS', 30),
        // The flashpoint map and list map show at most this many areas.
        'map_cap' => (int) env('BUFFALO_FLASHPOINT_MAP_CAP', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Threat reports (Phase 6B)
    |--------------------------------------------------------------------------
    | Without an expected end, a threat report expires this many days after
    | it is recorded. No expiry may be set more than `max_expiry_days` ahead
    | (from the day it is set). Owners are warned `expiry_warning_hours`
    | before expiry.
    */
    'threats' => [
        'default_expiry_days' => (int) env('BUFFALO_THREAT_DEFAULT_EXPIRY_DAYS', 14),
        'max_expiry_days' => (int) env('BUFFALO_THREAT_MAX_EXPIRY_DAYS', 90),
        'expiry_warning_hours' => (int) env('BUFFALO_THREAT_EXPIRY_WARNING_HOURS', 24),
        // "Expiring soon" on lists and dashboards.
        'expiring_soon_hours' => (int) env('BUFFALO_THREAT_EXPIRING_SOON_HOURS', 48),
        'map_cap' => (int) env('BUFFALO_THREAT_MAP_CAP', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational map (Phase 6C)
    |--------------------------------------------------------------------------
    | Aggregated layers are cached per scope signature for `cache_seconds`.
    | Zoom buckets: up to `max_zoom`, hexagons of `hex_meters` (flat-to-flat,
    | Web Mercator), computed per `tile_degrees` tile of a fixed grid.
    */
    'map' => [
        'cache_seconds' => (int) env('BUFFALO_MAP_CACHE_SECONDS', 300),
        'default_days' => (int) env('BUFFALO_MAP_DEFAULT_DAYS', 90),
        'zoom_buckets' => [
            ['max_zoom' => 6, 'hex_meters' => 50000, 'tile_degrees' => 8.0],
            ['max_zoom' => 8, 'hex_meters' => 20000, 'tile_degrees' => 4.0],
            ['max_zoom' => 10, 'hex_meters' => 5000, 'tile_degrees' => 1.0],
            ['max_zoom' => 12, 'hex_meters' => 2000, 'tile_degrees' => 0.5],
            ['max_zoom' => 99, 'hex_meters' => 500, 'tile_degrees' => 0.1],
        ],
        // Hard caps per layer response (features), to keep payloads small.
        'max_features' => (int) env('BUFFALO_MAP_MAX_FEATURES', 4000),
        'max_tiles' => (int) env('BUFFALO_MAP_MAX_TILES', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blackspot suggestions (Phase 6C)
    |--------------------------------------------------------------------------
    | Nightly DBSCAN clustering of counted incidents (last `days` days) per
    | state: clusters of at least `min_points` incidents within `eps_meters`
    | of each other. Dismissed suggestions are not regenerated within
    | `dismiss_radius_meters` for `dismiss_days`.
    */
    'blackspots' => [
        'eps_meters' => (int) env('BUFFALO_BLACKSPOT_EPS_METERS', 1000),
        'min_points' => (int) env('BUFFALO_BLACKSPOT_MIN_POINTS', 5),
        'days' => (int) env('BUFFALO_BLACKSPOT_DAYS', 180),
        'per_category' => (bool) env('BUFFALO_BLACKSPOT_PER_CATEGORY', false),
        'min_radius_meters' => 500,
        // A cluster wider than this is diffuse activity, not a spot: it is
        // re-clustered with half the eps (up to `refinements` times), and
        // dropped if still too wide.
        'max_radius_meters' => (int) env('BUFFALO_BLACKSPOT_MAX_RADIUS_METERS', 5000),
        'refinements' => 3,
        // A cluster must be denser than chance: under the state's background
        // density (Poisson), the probability of so many incidents in a
        // circle of its radius must be below `significance` divided by the
        // number of incidents considered (Bonferroni), so that chance
        // groupings in diffuse activity are not suggested.
        'significance' => (float) env('BUFFALO_BLACKSPOT_SIGNIFICANCE', 0.05),
        'dismiss_days' => (int) env('BUFFALO_BLACKSPOT_DISMISS_DAYS', 90),
        'dismiss_radius_meters' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports and exports (Phase 7D)
    |--------------------------------------------------------------------------
    | Gotenberg (internal network only) renders PDFs. Report files expire
    | `expiry_days` after completion. Incident list exports are capped at
    | `max_rows`; above `confirm_rows` the user confirms first.
    */
    'reports' => [
        'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'gotenberg_timeout' => (int) env('BUFFALO_GOTENBERG_TIMEOUT', 90),
        'expiry_days' => (int) env('BUFFALO_REPORT_EXPIRY_DAYS', 30),
        'max_rows' => (int) env('BUFFALO_REPORT_MAX_ROWS', 50000),
        'confirm_rows' => (int) env('BUFFALO_REPORT_CONFIRM_ROWS', 10000),
        // Report requests and row estimates per user per minute.
        'per_minute' => (int) env('BUFFALO_REPORT_PER_MINUTE', 20),
        // Queue connection for generation (a longer retry window than the default).
        'queue_connection' => env('BUFFALO_REPORTS_QUEUE_CONNECTION', 'redis-reports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics (Phase 7C)
    |--------------------------------------------------------------------------
    | Incident type codes counted as kidnapping for "abducted versus
    | rescued". Incident-based areas are cached like the map layers
    | (buffalo.map.cache_seconds, per scope signature).
    */
    'analytics' => [
        'kidnapping_type_codes' => array_values(array_filter(explode(',', (string) env('BUFFALO_ANALYTICS_KIDNAPPING_CODES', 'kidnapping')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global search (Phase 7B)
    |--------------------------------------------------------------------------
    | Results per type in the header (quick) view, per page and in all in
    | the full view (counts stay exact); each provider query's time budget; trigram thresholds (the `%`
    | and `<%` operators, index-backed); searches per user per minute.
    */
    'search' => [
        'min_length' => 2,
        'quick_limit' => (int) env('BUFFALO_SEARCH_QUICK_LIMIT', 5),
        'page_size' => (int) env('BUFFALO_SEARCH_PAGE_SIZE', 25),
        // Results listed per type on the results page (beyond: refine the search).
        'max_results' => (int) env('BUFFALO_SEARCH_MAX_RESULTS', 1000),
        'provider_timeout_ms' => (int) env('BUFFALO_SEARCH_PROVIDER_TIMEOUT_MS', 300),
        'similarity' => (float) env('BUFFALO_SEARCH_SIMILARITY', 0.3),
        'word_similarity' => (float) env('BUFFALO_SEARCH_WORD_SIMILARITY', 0.5),
        'per_minute' => (int) env('BUFFALO_SEARCH_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dissemination (Phase 7A)
    |--------------------------------------------------------------------------
    | Flashpoint disseminations last `flashpoint_days` and can be renewed;
    | threat report disseminations follow the threat's expiry. Recipients
    | who have not acknowledged are reminded once after the given hours.
    | Suggested targets without boundaries: LGAs whose centroid lies within
    | the area plus `centroid_margin_meters`.
    */
    'disseminations' => [
        'flashpoint_days' => (int) env('BUFFALO_DISSEMINATION_FLASHPOINT_DAYS', 90),
        'remind_immediate_hours' => (int) env('BUFFALO_DISSEMINATION_REMIND_IMMEDIATE_HOURS', 2),
        'remind_other_hours' => (int) env('BUFFALO_DISSEMINATION_REMIND_OTHER_HOURS', 24),
        'centroid_margin_meters' => (int) env('BUFFALO_DISSEMINATION_CENTROID_MARGIN', 20000),
        'reason_min_length' => 20,
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

    /*
    |--------------------------------------------------------------------------
    | Retention (Phase 8A.5)
    |--------------------------------------------------------------------------
    | Days to keep each group of records, or null to keep them forever. The
    | client has not yet set retention periods, so every group is null and
    | NOTHING IS DELETED: no deletion command exists yet. These values are
    | shown by `php artisan buffalo:retention:report` and will drive a future
    | retention command once the client decides (docs/security/retention.md).
    | The only automatic deletion today is expired report files
    | (`reports.expiry_days`, buffalo:expire-reports).
    */
    'retention' => [
        'audit' => $retentionDays('BUFFALO_RETENTION_AUDIT_DAYS'),                 // activity_log, record_access_logs, record_match_events, audit_anchors
        'login_history' => $retentionDays('BUFFALO_RETENTION_LOGIN_HISTORY_DAYS'), // login_histories
        'search_logs' => $retentionDays('BUFFALO_RETENTION_SEARCH_LOG_DAYS'),
        'analytics_exports' => $retentionDays('BUFFALO_RETENTION_ANALYTICS_EXPORT_DAYS'),
        'notifications' => $retentionDays('BUFFALO_RETENTION_NOTIFICATION_DAYS'),
        'report_runs' => $retentionDays('BUFFALO_RETENTION_REPORT_RUN_DAYS'),       // run records; the files expire after reports.expiry_days
        'incidents' => $retentionDays('BUFFALO_RETENTION_INCIDENT_DAYS'),           // incidents with their history, follow-ups and evidence
        'persons' => $retentionDays('BUFFALO_RETENTION_PERSON_DAYS'),
        'cleared_persons_of_interest' => $retentionDays('BUFFALO_RETENTION_CLEARED_POI_DAYS'),
        'threats_and_flashpoints' => $retentionDays('BUFFALO_RETENTION_THREAT_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser security headers (Phase 8B.1)
    |--------------------------------------------------------------------------
    | The Content Security Policy is sent by SecurityHeaders middleware with a
    | fresh nonce per request. `report_only` sends it as
    | Content-Security-Policy-Report-Only (violations are logged through
    | /csp-report) instead of enforcing it. `websocket` is the Reverb origin
    | the browser connects to (VITE_REVERB_*), added to connect-src.
    */
    'security' => [
        'csp' => [
            'report_only' => (bool) env('BUFFALO_CSP_REPORT_ONLY', false),
            'websocket' => env('BUFFALO_CSP_WEBSOCKET', sprintf(
                '%s://%s:%s',
                env('VITE_REVERB_SCHEME', 'https') === 'https' ? 'wss' : 'ws',
                env('VITE_REVERB_HOST', 'localhost'),
                env('VITE_REVERB_PORT', 443),
            )),
        ],
        // Strict-Transport-Security max-age, sent only when APP_URL is https.
        'hsts_seconds' => (int) env('BUFFALO_HSTS_SECONDS', 31536000),
    ],

    // Step-up authentication (Phase 8B.2): sensitive actions need a TOTP
    // code confirmed within this many minutes.
    'step_up' => [
        'minutes' => (int) env('BUFFALO_STEP_UP_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export controls (Phase 8B.3)
    |--------------------------------------------------------------------------
    | Daily quotas per user (Africa/Lagos day). Exceeding one blocks further
    | exports of that kind until midnight; viewing records is unaffected.
    | Scheduled reports do not count. Passing `alert_fraction` of a quota, or
    | exporting secret material more than `secret_alert_count` times in a
    | day, alerts the DG and the DDGs covering the user (once per rule per day).
    */
    'exports' => [
        'daily_rows' => (int) env('BUFFALO_EXPORT_DAILY_ROWS', 100000),
        'daily_report_runs' => (int) env('BUFFALO_EXPORT_DAILY_REPORT_RUNS', 50),
        'daily_evidence_downloads' => (int) env('BUFFALO_EXPORT_DAILY_EVIDENCE_DOWNLOADS', 100),
        'alert_fraction' => (float) env('BUFFALO_EXPORT_ALERT_FRACTION', 0.5),
        'secret_alert_count' => (int) env('BUFFALO_EXPORT_SECRET_ALERT_COUNT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Malware scanning (Phase 8B.4)
    |--------------------------------------------------------------------------
    | clamd over TCP on the internal scanning network. Uploads are scanned
    | before acceptance; when clamd is unavailable they are refused, except
    | evidence, which is held in quarantine and scanned when it returns.
    | Evidence above `inline_max_mb` is scanned on the queue. Super admins
    | are alerted when the signatures are older than `max_signature_age_days`.
    */
    'malware' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'connect_timeout' => (float) env('CLAMAV_CONNECT_TIMEOUT', 3),
        'scan_timeout' => (int) env('CLAMAV_SCAN_TIMEOUT', 300),
        'inline_max_mb' => (int) env('BUFFALO_MALWARE_INLINE_MAX_MB', 10),
        'max_signature_age_days' => (int) env('BUFFALO_MALWARE_MAX_SIGNATURE_AGE_DAYS', 7),
        'online_updates' => (bool) env('CLAMAV_ONLINE_UPDATES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups (Phase 8C)
    |--------------------------------------------------------------------------
    | The backup sidecars write their status to the backup_status volume,
    | mounted read-only here. Alerts (critical, to super admins) when the
    | newest backup is older than max_age_hours or WAL archiving lags more
    | than wal_lag_minutes.
    */
    'backups' => [
        'status_path' => env('BUFFALO_BACKUP_STATUS_PATH', '/var/lib/buffalo/backup-status'),
        'max_age_hours' => (int) env('BUFFALO_BACKUP_MAX_AGE_HOURS', 26),
        'wal_lag_minutes' => (int) env('BUFFALO_BACKUP_WAL_LAG_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | System health and alerts (Phase 8C.4)
    |--------------------------------------------------------------------------
    | buffalo:health:monitor (every minute) raises deduplicated alerts to super
    | admins. Disks are the volumes mounted in the application container;
    | BUFFALO_HEALTH_EXTRA_DISKS adds "name=/path" pairs (comma-separated).
    | /health/detailed answers only with BUFFALO_HEALTH_TOKEN (a secret) and
    | only from internal networks (nginx).
    */
    'health' => [
        'disks' => [
            'Evidence' => env('EVIDENCE_ROOT', '/var/lib/buffalo/evidence'),
            'Reports' => env('REPORTS_ROOT', '/var/lib/buffalo/reports'),
            'Quarantine' => env('QUARANTINE_ROOT', '/var/lib/buffalo/quarantine'),
            'Application storage' => storage_path(),
        ],
        'extra_disks' => env('BUFFALO_HEALTH_EXTRA_DISKS', ''),
        'disk_warning_percent' => (int) env('BUFFALO_HEALTH_DISK_WARNING_PERCENT', 80),
        'disk_critical_percent' => (int) env('BUFFALO_HEALTH_DISK_CRITICAL_PERCENT', 90),
        'failed_jobs' => (int) env('BUFFALO_HEALTH_FAILED_JOBS', 10),
        'queue_wait_minutes' => (int) env('BUFFALO_HEALTH_QUEUE_WAIT_MINUTES', 5),
        'scheduler_minutes' => (int) env('BUFFALO_HEALTH_SCHEDULER_MINUTES', 10),
        'nginx_url' => env('BUFFALO_HEALTH_NGINX_URL', 'http://nginx:8080/up'),
        'tls_certificate' => env('BUFFALO_TLS_CERTIFICATE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pilot rollout (Phase 9B.3)
    |--------------------------------------------------------------------------
    | Until a state is live, its state- and LGA-level users cannot sign in.
    | Always enforced in production (buffalo:production:check refuses false);
    | the test suite switches it off except in the rollout tests.
    */
    'rollout' => [
        'enforce' => (bool) env('BUFFALO_ROLLOUT_ENFORCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Go-live check (Phase 9B.6)
    |--------------------------------------------------------------------------
    | buffalo:go-live:check runs daily and on demand. `version` is baked into
    | the release image (BUFFALO_VERSION). TLS is read from the certificate
    | nginx actually serves on the internal network.
    */
    'golive' => [
        'version' => env('BUFFALO_VERSION', 'dev'),
        'tls_host' => env('BUFFALO_GOLIVE_TLS_HOST', 'nginx'),
        'tls_port' => (int) env('BUFFALO_GOLIVE_TLS_PORT', 8443),
        'tls_min_days' => (int) env('BUFFALO_GOLIVE_TLS_MIN_DAYS', 30),
        'full_backup_max_days' => (int) env('BUFFALO_GOLIVE_FULL_BACKUP_MAX_DAYS', 8),
        'drill_max_days' => (int) env('BUFFALO_GOLIVE_DRILL_MAX_DAYS', 30),
        'audit_verify_max_hours' => (int) env('BUFFALO_GOLIVE_AUDIT_VERIFY_MAX_HOURS', 24),
        'report_disk' => env('BUFFALO_GOLIVE_REPORT_DISK', 'local'),
        'exceptions_file' => base_path('security/vulnerability-exceptions.yaml'),
    ],

];
