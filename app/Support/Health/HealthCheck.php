<?php

namespace App\Support\Health;

/**
 * One group of health checks for buffalo:health:monitor (Phase 8C). Returns
 * the conditions present now; conditions no longer returned are cleared.
 * Each check owns a key prefix (e.g. "backup.") so only its own alerts are
 * cleared when it runs.
 */
interface HealthCheck
{
    public function prefix(): string;

    /** @return list<Condition> */
    public function conditions(): array;
}
