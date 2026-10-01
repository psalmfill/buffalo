<?php

namespace App\Actions\Threats;

use App\Enums\NotificationSeverity;
use App\Enums\ThreatStatus;
use App\Events\Threats\ThreatReportChanged;
use App\Models\ThreatReport;
use App\Support\Audit\AuditLog;
use App\Support\Threats\ThreatNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Runs every 15 minutes in the system context (Phase 6B):
 * - active threat reports past `expires_at` become `expired` (named event
 *   "expired", no causer);
 * - those expiring within `expiry_warning_hours` are reported once per
 *   expiry time to their creator and the SD/DSD of the state.
 */
final class ExpireThreatReports
{
    public function __construct(private readonly ThreatNotifier $notifier) {}

    /**
     * @return array{expired: int, warned: int}
     */
    public function __invoke(): array
    {
        $now = CarbonImmutable::now();
        $expired = 0;

        foreach (ThreatReport::query()->active()->where('expires_at', '<=', $now)->get() as $threat) {
            DB::transaction(function () use ($threat, $now, &$expired): void {
                $updated = ThreatReport::query()->whereKey($threat->id)->active()->where('expires_at', '<=', $now)
                    ->update(['status' => ThreatStatus::Expired->value, 'status_note' => 'Expired automatically.', 'updated_at' => $now]);

                if ($updated === 1) {
                    AuditLog::event('expired', 'Expired automatically', $threat, ['expires_at' => $threat->expires_at->toIso8601String()]);
                    ThreatReportChanged::dispatch($threat);
                    $expired++;
                }
            });
        }

        $warned = 0;
        $soon = ThreatReport::query()->active()
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addHours((int) config('buffalo.threats.expiry_warning_hours')))
            ->where(fn ($q) => $q->whereNull('expiry_warned_for')->orWhereColumn('expiry_warned_for', '<>', 'expires_at'))
            ->with(['threatType', 'lga'])
            ->get();

        foreach ($soon as $threat) {
            // Bookkeeping only (not audited: ThreatReport::auditExcept()).
            ThreatReport::query()->whereKey($threat->id)->toBase()->update(['expiry_warned_for' => $threat->expires_at]);
            $warned += $this->notifier->send($threat, 'expiring', $this->notifier->creator($threat)->merge($this->notifier->stateLeads($threat)), NotificationSeverity::Info);
        }

        return ['expired' => $expired, 'warned' => $warned];
    }
}
