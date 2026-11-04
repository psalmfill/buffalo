<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\State;
use App\Notifications\CoverageReportMissing;
use App\Notifications\CoverageReportsMissingSummary;
use App\Support\Notifications\NotifyRole;
use App\Support\Security\CoverageSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily (Phase 4A): once the grace days after the last completed period
 * have passed, tells each LGA's security chief that the LGA's coverage
 * report for that period is missing, and each State Director which LGAs of
 * the state are missing it. Each goes out once per period
 * (coverage_reminders). Any security report submitted during the period
 * counts, whatever its period type (Phase 11G), as does a report for
 * exactly that period.
 */
class SendCoverageReminders extends Command
{
    protected $signature = 'buffalo:coverage-reminders';

    protected $description = 'Remind LGAs and State Directors of missing security reports';

    public function handle(CoverageSchedule $schedule, NotifyRole $notify): int
    {
        $today = CarbonImmutable::today();
        $type = $schedule->periodType();
        [$start, $end] = $schedule->lastCompletedPeriod($today);

        if ($today->lessThanOrEqualTo($schedule->dueDate($end))) {
            $this->components->info('Within the grace period: no reminders yet.');

            return self::SUCCESS;
        }

        $label = $type->describe($start, $end);
        // Any report submitted during the period counts (Phase 11G).
        $reported = $schedule->countingFor(DB::table('coverage_reports')->whereNull('deleted_at'), $start, $end)
            ->pluck('lga_id');

        $missing = Lga::query()->active()->with('state')->whereNotIn('id', $reported)->orderBy('name')->get();
        $lgaReminders = 0;
        $stateReminders = 0;

        foreach ($missing as $lga) {
            if ($this->firstTime('lga', $lga->id, $type->value, $start)) {
                $notify->send(new CoverageReportMissing($lga->name, $label), Role::LgaSecurityChief, $lga);
                $lgaReminders++;
            }
        }

        foreach ($missing->groupBy('state_id') as $lgas) {
            /** @var State $state */
            $state = $lgas->first()->state;

            if ($this->firstTime('state', $state->id, $type->value, $start)) {
                $names = array_values($lgas->pluck('name')->all());
                $notify->send(new CoverageReportsMissingSummary($state->name, $label, $names), Role::StateDirector, $state);
                $stateReminders++;
            }
        }

        $this->components->info("Missing {$label} reports: {$missing->count()}; LGA reminders: {$lgaReminders}; state reminders: {$stateReminders}.");

        return self::SUCCESS;
    }

    /** Records the reminder; false when it was already sent. */
    private function firstTime(string $scope, int $placeId, string $periodType, CarbonImmutable $start): bool
    {
        return DB::table('coverage_reminders')->insertOrIgnore([
            'scope' => $scope,
            'place_id' => $placeId,
            'period_type' => $periodType,
            'period_start' => $start->toDateString(),
            'sent_at' => now(),
        ]) === 1;
    }
}
