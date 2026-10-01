<?php

namespace App\Actions\Threats;

use App\Actions\Disseminations\SyncThreatDisseminations;
use App\Enums\ThreatStatus;
use App\Events\Threats\ThreatReportChanged;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Extends an active threat report, or reactivates an expired, mitigated or
 * withdrawn one (Phase 6B), with a new expiry (within the maximum from
 * today, checked by the request) and a required comment. Named events
 * "extended" and "reactivated". The 24-hour warning is re-armed, and its
 * disseminations take the new expiry (Phase 7A).
 */
final class SetThreatExpiry
{
    public function __construct(private readonly SyncThreatDisseminations $disseminations) {}

    public function extend(ThreatReport $threat, CarbonImmutable $expiresAt, string $comment, User $actor): void
    {
        $this->apply($threat, $expiresAt, $comment, $actor, reactivate: false);
    }

    public function reactivate(ThreatReport $threat, CarbonImmutable $expiresAt, string $comment, User $actor): void
    {
        $this->apply($threat, $expiresAt, $comment, $actor, reactivate: true);
    }

    private function apply(ThreatReport $threat, CarbonImmutable $expiresAt, string $comment, User $actor, bool $reactivate): void
    {
        DB::transaction(function () use ($threat, $expiresAt, $comment, $actor, $reactivate): void {
            $locked = ThreatReport::query()->whereKey($threat->id)->lockForUpdate()->firstOrFail();

            if ($reactivate ? ! $locked->status->canReactivate() : ! $locked->isActive()) {
                throw ValidationException::withMessages(['expires_at' => $reactivate
                    ? 'Only an expired, mitigated or withdrawn threat report can be reactivated.'
                    : 'Only an active threat report can be extended.']);
            }

            $old = ['status' => $locked->status->value, 'expires_at' => $locked->expires_at->toIso8601String()];
            $locked->forceFill([
                'expires_at' => $expiresAt,
                'expiry_warned_for' => null,
                ...($reactivate ? ['status' => ThreatStatus::Active, 'status_note' => null] : []),
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event(
                $reactivate ? 'reactivated' : 'extended',
                $reactivate ? "Reactivated until {$expiresAt->format('j M Y H:i')}" : "Expiry extended to {$expiresAt->format('j M Y H:i')}",
                $locked,
                ['old' => $old, 'new' => ['status' => $locked->status->value, 'expires_at' => $expiresAt->toIso8601String()], 'comment' => $comment],
                $actor,
            );

            // Disseminations follow the threat's expiry (Phase 7A).
            ($this->disseminations)($locked);

            ThreatReportChanged::dispatch($locked);
        });
    }
}
