<?php

namespace App\Console\Commands;

use App\Actions\Reports\RequestReport;
use App\Enums\ReportType;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled every 5 minutes (Phase 7D): queues a run for each due report
 * schedule, requested as the schedule's owner and validated in their
 * context (the run itself is generated with their scopes at that time). A
 * SITREP covers everything since the schedule's previous run; weekly and
 * monthly summaries cover the last complete week or month.
 *
 * Schedules whose owner is deactivated, deleted, locked or no longer
 * allowed to schedule that report are paused rather than run.
 */
class RunReportSchedules extends Command
{
    protected $signature = 'buffalo:run-report-schedules';

    protected $description = 'Queue report runs for due report schedules';

    public function handle(JurisdictionContext $context, RequestReport $request): int
    {
        $now = CarbonImmutable::now();
        $queued = $paused = 0;

        $due = ReportSchedule::query()->where('is_active', true)->where('next_run_at', '<=', $now)->orderBy('next_run_at')->get();

        foreach ($due as $schedule) {
            /** @var User|null $owner */
            $owner = User::query()->withTrashed()->find($schedule->user_id);

            if ($owner === null || $owner->isAccessBlocked() || ! $owner->can('schedule', [ReportSchedule::class, $schedule->report_type])) {
                $schedule->forceFill(['is_active' => false])->save();
                $paused++;

                continue;
            }

            $input = $schedule->parameters;
            if ($schedule->report_type === ReportType::Sitrep && $schedule->last_run_at !== null) {
                $input['since'] = $schedule->last_run_at->toIso8601String();
            }

            try {
                $context->actingAs($owner, fn () => $request($owner, $schedule->report_type, $schedule->format, $input, $schedule));
                $queued++;
            } catch (Throwable $e) {
                // E.g. the saved place is no longer within the owner's reach.
                Log::warning('Report schedule skipped', ['schedule' => $schedule->ulid, 'exception' => $e::class]);
            }

            $schedule->forceFill(['last_run_at' => $now, 'next_run_at' => $schedule->nextRunAfter($now)])->save();
        }

        $this->components->info("Report runs queued: {$queued}; schedules paused: {$paused}.");

        return self::SUCCESS;
    }
}
