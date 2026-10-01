<?php

namespace App\Actions\Disseminations;

use App\Models\RecordDissemination;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Renews a flashpoint dissemination (Phase 7A) for another
 * `flashpoint_days` from now, active or expired (never a revoked one).
 * The reminder and expiry notice are re-armed. Named audit event "renewed".
 */
final class RenewDissemination
{
    public function __invoke(RecordDissemination $dissemination, User $actor): void
    {
        $old = $dissemination->expires_at;
        $expiresAt = CarbonImmutable::now()->addDays((int) config('buffalo.disseminations.flashpoint_days'));

        $dissemination->forceFill([
            'expires_at' => $expiresAt,
            'reminded_at' => null,
            'expiry_notified_at' => null,
        ])->save();

        AuditLog::event('renewed', "Renewed until {$expiresAt->format('j M Y')}", $dissemination, [
            'old' => ['expires_at' => $old->toIso8601String()],
            'new' => ['expires_at' => $expiresAt->toIso8601String()],
        ], $actor);
    }
}
