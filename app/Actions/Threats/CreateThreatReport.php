<?php

namespace App\Actions\Threats;

use App\Enums\NotificationSeverity;
use App\Enums\ThreatUrgency;
use App\Events\Threats\ThreatReportCreated;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Threats\ThreatNotifier;
use App\Support\Threats\ThreatReferenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a threat report (Phase 6B): reference allocated in the same
 * transaction (gap-free), initial flashpoint and group links, then a live
 * event and the notifications by urgency:
 * - immediate: SD, DSD, LGA chief, DG and covering DDGs (critical);
 * - priority: SD, DSD, LGA chief (warning);
 * - routine: nobody now; the SD/DSD get it in the 07:00 digest.
 */
final class CreateThreatReport
{
    public function __construct(
        private readonly ThreatReferenceAllocator $references,
        private readonly LinkToThreatReport $link,
        private readonly ThreatNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ThreatReportRequest::values()
     * @param  list<Flashpoint>  $flashpoints
     * @param  list<array{group: CriminalGroup, role: string}>  $groups
     */
    public function __invoke(Lga $lga, array $data, array $flashpoints, array $groups, User $actor): ThreatReport
    {
        $threat = DB::transaction(function () use ($lga, $data, $flashpoints, $groups, $actor): ThreatReport {
            $now = CarbonImmutable::now();
            $threat = new ThreatReport;
            $threat->forceFill([
                ...ThreatReportAttributes::from($data),
                'reference_no' => $this->references->allocate($lga->state_id, $now),
                'credibility' => $data['credibility'],
                'urgency' => $data['urgency'],
                'expires_at' => $data['expires_at'],
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            foreach ($flashpoints as $flashpoint) {
                $this->link->record($threat, $flashpoint, null, $actor);
            }

            foreach ($groups as $row) {
                $this->link->record($threat, $row['group'], $row['role'], $actor);
            }

            ThreatReportCreated::dispatch($threat);

            return $threat;
        });

        match ($threat->urgency) {
            ThreatUrgency::Immediate => $this->notifier->sendImmediate($threat, 'created_immediate', [$actor->id]),
            ThreatUrgency::Priority => $this->notifier->send($threat, 'created_priority', $this->notifier->local($threat), NotificationSeverity::Warning, [$actor->id]),
            ThreatUrgency::Routine => 0,
        };

        return $threat;
    }
}
