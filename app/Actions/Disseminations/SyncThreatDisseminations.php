<?php

namespace App\Actions\Disseminations;

use App\Models\RecordDissemination;
use App\Models\ThreatReport;

/**
 * A threat report's disseminations follow its expiry (Phase 7A): when it is
 * extended or reactivated, every unrevoked dissemination (onward ones
 * included) takes the new expiry, and an ended one is active again with its
 * expiry notice re-armed. Expiry itself needs nothing: the rows already
 * end at the threat's expiry.
 */
final class SyncThreatDisseminations
{
    public function __invoke(ThreatReport $threat): int
    {
        return RecordDissemination::query()->about($threat)->whereNull('revoked_at')->update([
            'expires_at' => $threat->expires_at,
            'expiry_notified_at' => $threat->expires_at->isFuture() ? null : now(),
            'updated_at' => now(),
        ]);
    }
}
