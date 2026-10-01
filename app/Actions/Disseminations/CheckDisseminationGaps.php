<?php

namespace App\Actions\Disseminations;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use App\Enums\NotificationSeverity;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Flashpoint;
use App\Models\ThreatReport;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationTargets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Daily, in the system context (Phase 7A): active immediate or priority
 * threat reports and active or monitoring high or severe flashpoints whose
 * areas reach other LGAs or states that no active dissemination covers.
 * The SD and DSD of the owning state are told once per record (warning);
 * dissemination_gap_alerts remembers which records were reported.
 */
final class CheckDisseminationGaps
{
    public function __construct(
        private readonly DisseminationTargets $targets,
        private readonly DisseminationNotifier $notifier,
    ) {}

    public function __invoke(): int
    {
        $reported = 0;

        foreach ($this->candidates() as $subject) {
            if ($this->targets->uncovered($subject) === []) {
                continue;
            }

            $inserted = DB::table('dissemination_gap_alerts')->insertOrIgnore([
                'subject_type' => $subject->disseminationType(),
                'subject_id' => $subject->getKey(),
                'alerted_at' => CarbonImmutable::now(),
            ]);

            if ($inserted === 1) {
                $this->notifier->send($this->notifier->owners($subject), $subject, 'gap', NotificationSeverity::Warning);
                $reported++;
            }
        }

        return $reported;
    }

    /**
     * @return iterable<Model&Disseminable>
     */
    private function candidates(): iterable
    {
        $alerted = fn (string $type) => DB::table('dissemination_gap_alerts')->where('subject_type', $type)->select('subject_id');

        yield from ThreatReport::query()
            ->where('status', ThreatStatus::Active->value)
            ->whereIn('urgency', [ThreatUrgency::Immediate->value, ThreatUrgency::Priority->value])
            ->whereNotNull('location')->whereNotNull('radius_meters')
            ->whereNotIn('id', $alerted('threat_report'))
            ->lazyById();

        yield from Flashpoint::query()
            ->whereIn('status', [FlashpointStatus::Active->value, FlashpointStatus::Monitoring->value])
            ->whereIn('concern_level', [ConcernLevel::High->value, ConcernLevel::Severe->value])
            ->whereNotIn('id', $alerted('flashpoint'))
            ->lazyById();
    }
}
