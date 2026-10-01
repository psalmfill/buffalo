<?php

namespace App\Console\Commands;

use App\Enums\ThreatUrgency;
use App\Models\ThreatReport;
use App\Models\User;
use App\Notifications\ThreatDigest;
use App\Support\Threats\ThreatNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily at 07:00 (Phase 6B): routine threat reports recorded in the last 24
 * hours (and still on record), sent to the SD and DSD of each state as one
 * digest. Each recipient's digest lists only the reports they can see.
 */
class SendThreatDigest extends Command
{
    protected $signature = 'buffalo:threat-digest';

    protected $description = 'Send State Directors and Deputies the daily digest of routine threat reports';

    /** References listed in one digest; the count covers the rest. */
    private const LISTED = 20;

    public function handle(ThreatNotifier $notifier): int
    {
        $threats = ThreatReport::query()
            ->with(['threatType', 'lga'])
            ->where('urgency', ThreatUrgency::Routine->value)
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())
            ->orderBy('created_at')
            ->get();

        $sent = 0;

        foreach ($threats->groupBy('state_id') as $stateThreats) {
            /** @var ThreatReport $first */
            $first = $stateThreats->first();

            foreach ($notifier->stateLeads($first)->unique('id') as $user) {
                /** @var User $user */
                $visible = $stateThreats->filter(fn (ThreatReport $t) => $user->can('view', $t))->values();

                if ($visible->isEmpty()) {
                    continue;
                }

                $lines = $visible->take(self::LISTED)
                    ->map(fn (ThreatReport $t) => "{$t->reference_no} — {$t->threatType->name}, {$t->lga->name} LGA")
                    ->values()->all();

                Notification::send($user, new ThreatDigest($visible->count(), array_values($lines)));
                $sent++;
            }
        }

        $this->components->info("Threat digests sent: {$sent}.");

        return self::SUCCESS;
    }
}
