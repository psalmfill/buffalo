<?php

namespace App\Support\Health;

use App\Notifications\BuffaloNotification;

/**
 * A problem a health check found (Phase 8C), identified by a stable key so
 * it is alerted once until it clears. Never carries intelligence content.
 */
final readonly class Condition
{
    /**
     * @param  'warning'|'critical'  $severity
     */
    public function __construct(
        public string $key,
        public string $severity,
        public string $title,
        public string $detail,
        public bool $alsoDirectorGeneral = false,
        // Sent instead of the generic SystemAlert when the condition is raised.
        public ?BuffaloNotification $notification = null,
    ) {}

    public static function critical(string $key, string $title, string $detail, bool $alsoDirectorGeneral = false, ?BuffaloNotification $notification = null): self
    {
        return new self($key, 'critical', $title, $detail, $alsoDirectorGeneral, $notification);
    }

    public static function warning(string $key, string $title, string $detail): self
    {
        return new self($key, 'warning', $title, $detail);
    }
}
