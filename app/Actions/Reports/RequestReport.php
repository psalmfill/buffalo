<?php

namespace App\Actions\Reports;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Exceptions\StepUpRequired;
use App\Jobs\GenerateReport;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\Reports\Builders\IncidentListBuilder;
use App\Services\Reports\ReportParameters;
use App\Support\Exports\ExportControls;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues a report run (Phase 7D) after validating its parameters in the
 * requester's context. Incident list exports above
 * `buffalo.reports.max_rows` are refused; above `confirm_rows` they need
 * step-up authentication (Phase 8B.2: a TOTP code within the last 15
 * minutes, replacing the plain confirmation; the page shows the row count and that the export is
 * logged). The caller has already authorised `generate`.
 */
final class RequestReport
{
    public function __construct(
        private readonly ReportParameters $parameters,
        private readonly ExportControls $exports,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, ReportType $type, ReportFormat $format, array $input, ?ReportSchedule $schedule = null, bool $stepUpConfirmed = false): ReportRun
    {
        if (! in_array($format, $type->formats(), true)) {
            throw ValidationException::withMessages(['format' => 'Choose a format offered for this report.']);
        }

        $parameters = $this->parameters->validate($type, $input, $user);

        if (isset($input['since']) && $schedule !== null) {
            // Scheduled SITREPs cover everything since the previous run.
            $parameters['since'] = (string) $input['since'];
        }

        $rows = null;

        if ($type === ReportType::IncidentList) {
            $rows = self::estimate($user, $parameters);

            if ($rows > (int) config('buffalo.reports.max_rows')) {
                throw ValidationException::withMessages(['filters' => 'This selection has '.number_format($rows).' incidents; exports are limited to '.number_format((int) config('buffalo.reports.max_rows')).'. Narrow the filters.']);
            }

        }

        // Daily quotas (Phase 8B.3), before asking for a code: scheduled runs do not count.
        if ($schedule === null) {
            $this->exports->assertCanRequestReport($user, $rows);
        }

        if ($rows !== null && $rows > (int) config('buffalo.reports.confirm_rows') && ! $stepUpConfirmed) {
            throw new StepUpRequired;
        }

        $run = DB::transaction(function () use ($user, $type, $format, $parameters, $schedule, $rows): ReportRun {
            $run = new ReportRun;
            $run->forceFill([
                'report_type' => $type,
                'format' => $format,
                'parameters' => $parameters,
                'requested_by' => $user->id,
                'report_schedule_id' => $schedule?->id,
                // The estimate counts toward today's rows until generation sets the real count.
                'row_count' => $rows,
            ])->save();

            return $run;
        });

        GenerateReport::dispatch($run->id);

        if ($schedule === null) {
            $this->exports->afterExport($user);
        }

        return $run;
    }

    /**
     * Rows an incident list export would contain, through the requester's scopes.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function estimate(User $user, array $parameters): int
    {
        return IncidentListBuilder::query($user, (array) ($parameters['filters'] ?? []))->count();
    }
}
