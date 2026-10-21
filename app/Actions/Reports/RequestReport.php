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
use App\Services\Reports\Builders\ListExportBuilder;
use App\Services\Reports\ReportParameters;
use App\Support\Exports\ExportControls;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Exports\SensitiveExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues a report run (Phase 7D) after validating its parameters in the
 * requester's context. The caller has already authorised `generate` (or
 * `exportList` for a list export).
 *
 * List exports (Phase 11E):
 * - refused above the list's row cap (buffalo.reports.max_rows, the
 *   sensitive lists buffalo.exports.sensitive_max_rows; PDF tables
 *   buffalo.exports.pdf_max_rows);
 * - step-up (a TOTP code within the last 15 minutes, Phase 8B.2) above
 *   buffalo.reports.confirm_rows, and for every export of a sensitive list
 *   or the users list;
 * - sensitive lists also need a reason (at least 20 characters), stored on
 *   the run, count toward a separate daily limit, and alert the DG and the
 *   covering DDGs at once.
 * Person PDFs always need step-up (they may hold encrypted fields).
 */
final class RequestReport
{
    public function __construct(
        private readonly ReportParameters $parameters,
        private readonly ExportControls $exports,
        private readonly SensitiveExports $sensitive,
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
        $exporter = $type === ReportType::ListExport ? ListExporters::find((string) $parameters['list']) : null;
        $reason = null;

        if ($exporter !== null) {
            $rows = $exporter->count($user, (array) $parameters['filters']);
            $limit = ListExportBuilder::limit($exporter, $format);

            if ($rows > $limit) {
                throw ValidationException::withMessages(['filters' => 'This selection has '.number_format($rows).' rows; '
                    .($format === ReportFormat::Pdf ? 'PDF tables' : 'exports of this list').' are limited to '.number_format($limit).'. Narrow the filters'
                    .($format === ReportFormat::Pdf ? ' or choose XLSX or CSV.' : '.')]);
            }

            if ($exporter->sensitive()) {
                $reason = self::reason($input);
            }
        }

        // Daily quotas (Phase 8B.3), before asking for a code: scheduled runs do not count.
        if ($schedule === null) {
            $this->exports->assertCanRequestReport($user, $rows);

            if ($exporter?->sensitive()) {
                $this->exports->assertCanExportSensitive($user);
            }
        }

        if (! $stepUpConfirmed && $this->needsStepUp($type, $exporter, $rows)) {
            throw new StepUpRequired;
        }

        $run = DB::transaction(function () use ($user, $type, $format, $parameters, $schedule, $rows, $exporter, $reason): ReportRun {
            $run = new ReportRun;
            $run->forceFill([
                'report_type' => $type,
                'format' => $format,
                'parameters' => $parameters,
                'requested_by' => $user->id,
                'report_schedule_id' => $schedule?->id,
                // The estimate counts toward today's rows until generation sets the real count.
                'row_count' => $rows,
                'is_sensitive' => $exporter?->sensitive() ?? false,
                'reason' => $reason,
            ])->save();

            return $run;
        });

        GenerateReport::dispatch($run->id);

        if ($schedule === null) {
            $this->exports->afterExport($user);
        }

        if ($exporter !== null && $exporter->sensitive()) {
            $this->sensitive->alert($run, $exporter, $user);
        }

        return $run;
    }

    /**
     * Rows an incident list export would contain, through the requester's
     * scopes (runs requested before Phase 11E).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function estimate(User $user, array $parameters): int
    {
        return IncidentListBuilder::query($user, (array) ($parameters['filters'] ?? []))->count();
    }

    /** Whether an export of this many rows needs a fresh TOTP code. */
    public static function needsStepUpFor(ListExporter $exporter, int $rows): bool
    {
        return $exporter->alwaysStepUp() || $rows > (int) config('buffalo.reports.confirm_rows');
    }

    private function needsStepUp(ReportType $type, ?ListExporter $exporter, ?int $rows): bool
    {
        return match (true) {
            $type === ReportType::Person => true,
            $exporter !== null => self::needsStepUpFor($exporter, (int) $rows),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function reason(array $input): string
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        $min = (int) config('buffalo.exports.sensitive_reason_min');

        if (mb_strlen($reason) < $min || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => "State why you need this export, in at least {$min} characters (it is recorded and sent to the Director General)."]);
        }

        return $reason;
    }
}
