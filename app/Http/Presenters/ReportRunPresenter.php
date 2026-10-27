<?php

namespace App\Http\Presenters;

use App\Enums\ReportType;
use App\Enums\SchedulePauseReason;
use App\Models\CoverageReport;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\State;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Reports\Builders\Places;
use App\Services\Reports\ReportParameters;
use App\Support\Exports\Lists\ListExporters;
use Carbon\CarbonImmutable;

/**
 * Report runs and schedules for the browser (Phase 7D): ULIDs and labels,
 * never ids or file paths. Runs are shown to their requester only.
 */
final class ReportRunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function run(ReportRun $run, User $user): array
    {
        return [
            'ulid' => $run->ulid,
            'reference' => $run->reference(),
            'type' => ['value' => $run->report_type->value, 'label' => $run->report_type->label()],
            'format' => $run->format->value,
            'status' => ['value' => $run->status->value, 'label' => $run->status->label()],
            'classification' => $run->status->value === 'completed' || $run->status->value === 'expired' ? $run->classification->label() : null,
            'summary' => self::summary($run->report_type, $run->parameters, $user),
            'scheduled' => $run->report_schedule_id !== null,
            'created_at' => $run->created_at->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'size_bytes' => $run->size_bytes,
            'row_count' => $run->row_count,
            'page_count' => $run->page_count,
            'error' => $run->error_message,
            'downloadable' => $run->isDownloadable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(ReportSchedule $schedule, User $user): array
    {
        return [
            'ulid' => $schedule->ulid,
            'type' => ['value' => $schedule->report_type->value, 'label' => $schedule->report_type->label()],
            'summary' => self::summary($schedule->report_type, $schedule->parameters, $user),
            'cadence' => $schedule->cadence,
            'time_of_day' => $schedule->time_of_day,
            'weekday' => $schedule->weekday,
            'day_of_month' => $schedule->day_of_month,
            'describe' => $schedule->describe(),
            'is_active' => $schedule->is_active,
            'paused_reason' => $schedule->is_active ? null : $schedule->paused_reason?->label(),
            'review' => ! $schedule->is_active && $schedule->paused_reason === SchedulePauseReason::PostingChange,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at->toIso8601String(),
        ];
    }

    /**
     * What a run covers, read through the viewer's scopes (a record that is
     * no longer visible is described generically).
     *
     * @param  array<string, mixed>  $p
     */
    public static function summary(ReportType $type, array $p, User $user): string
    {
        $tz = (string) config('app.timezone');

        return match ($type) {
            ReportType::Sitrep => Places::from($p)->label($user).(isset($p['since']) ? ' · since '.CarbonImmutable::parse((string) $p['since'])->setTimezone($tz)->format('j M H:i') : ''),
            ReportType::Weekly => Places::from($p)->label($user).(isset($p['end']) ? ' · week ending '.CarbonImmutable::parse((string) $p['end'], $tz)->format('j M Y') : ' · last week'),
            ReportType::Monthly => Places::from($p)->label($user).(isset($p['month']) ? ' · '.CarbonImmutable::createFromFormat('!Y-m', (string) $p['month'], $tz)?->format('F Y') : ' · last month'),
            ReportType::IncidentList => count((array) ($p['filters'] ?? [])) === 0 ? 'All incidents in your jurisdiction' : count((array) $p['filters']).' filter(s) applied',
            ReportType::Incident => Incident::query()->where('ulid', (string) ($p['incident'] ?? ''))->value('reference_no') ?? 'An incident',
            ReportType::LgaProfile => (Lga::query()->where('ulid', (string) ($p['lga'] ?? ''))->value('name') ?? 'An').' LGA',
            ReportType::StateProfile => (State::query()->where('ulid', (string) ($p['state'] ?? ''))->value('name') ?? 'A').' State',
            ReportType::Coverage => ($r = CoverageReport::query()->with('lga')->where('ulid', (string) ($p['report'] ?? ''))->first()) === null ? 'A coverage report' : $r->lga->name.' LGA · '.$r->periodLabel(),
            ReportType::Analytics => (app(Analytics::class)->area($user, (string) ($p['tab'] ?? ''))?->label() ?? 'Analytics'),
            ReportType::ListExport => (ListExporters::find((string) ($p['list'] ?? ''))?->label() ?? 'A list')
                .(count((array) ($p['filters'] ?? [])) === 0 ? ' · no filters' : ' · '.count((array) $p['filters']).' filter(s) applied'),
            ReportType::Flashpoint, ReportType::OrganizationSite, ReportType::Arrest, ReportType::ThreatReport,
            ReportType::CriminalGroup, ReportType::Person => self::record($type, $p),
        };
    }

    /**
     * A single record's name, through the viewer's scopes (generic when it
     * is no longer visible).
     *
     * @param  array<string, mixed>  $p
     */
    private static function record(ReportType $type, array $p): string
    {
        $class = ReportParameters::recordClass($type);
        $record = $class === null ? null : $class::query()->where('ulid', (string) ($p[(string) $type->recordParameter()] ?? ''))->first();

        return match (true) {
            $record === null => 'A record no longer visible to you',
            $record instanceof Flashpoint, $record instanceof OrganizationSite, $record instanceof CriminalGroup, $record instanceof Person => (string) $record->getAttribute('name'),
            default => (string) $record->getAttribute('reference_no'),
        };
    }
}
