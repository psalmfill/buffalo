<?php

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Models\CoverageReport;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsFilters;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises report parameters per type (Phase 7D), in the
 * requester's own context (geography within reach; single records the
 * requester can `view`). Generation re-reads everything through the
 * requester's scopes at that time.
 *
 * | Type          | Parameters                                                      |
 * |---------------|-----------------------------------------------------------------|
 * | sitrep        | state?, lga? (within reach); since? (scheduled runs only)       |
 * | weekly        | end? (Y-m-d, default yesterday); state?, lga?                   |
 * | monthly       | month? (Y-m, default last month); state?, lga?                  |
 * | incident_list | filters (IncidentFilters), confirmed? (large exports)           |
 * | incident      | incident (ULID)                                                 |
 * | lga_profile   | lga (ULID)                                                      |
 * | coverage      | report (ULID)                                                   |
 * | analytics     | tab, filters (AnalyticsFilters values)                          |
 */
final class ReportParameters
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(ReportType $type, array $input, User $user): array
    {
        return match ($type) {
            ReportType::Sitrep => $this->place($input, $user),
            ReportType::Weekly => [...$this->place($input, $user), 'end' => $this->date($input['end'] ?? null, CarbonImmutable::yesterday((string) config('app.timezone')))],
            ReportType::Monthly => [...$this->place($input, $user), 'month' => $this->month($input['month'] ?? null)],
            ReportType::IncidentList => ['filters' => IncidentFilters::from((array) ($input['filters'] ?? []))->values],
            ReportType::Incident => ['incident' => $this->record(Incident::class, $input['incident'] ?? null, $user, 'incident')],
            ReportType::LgaProfile => ['lga' => $this->lga($input['lga'] ?? null, $user)],
            ReportType::Coverage => ['report' => $this->record(CoverageReport::class, $input['report'] ?? null, $user, 'report')],
            ReportType::Analytics => $this->analytics($input, $user),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function place(array $input, User $user): array
    {
        $lga = is_string($input['lga'] ?? null) && $input['lga'] !== '' ? $this->options->lgaQuery($user)->where('ulid', $input['lga'])->first() : null;
        $state = $lga === null && is_string($input['state'] ?? null) && $input['state'] !== '' ? $this->options->states($user)->firstWhere('ulid', $input['state']) : null;

        if ((($input['lga'] ?? '') !== '' && $lga === null) || (($input['state'] ?? '') !== '' && $lga === null && $state === null)) {
            throw ValidationException::withMessages(['state' => 'Choose a place within your jurisdiction.']);
        }

        return array_filter(['state' => $state?->ulid, 'lga' => $lga?->ulid]);
    }

    private function date(mixed $value, CarbonImmutable $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.timezone'));

        if ($date === null || $date->isFuture()) {
            throw ValidationException::withMessages(['end' => 'Choose a past date.']);
        }

        return $date->toDateString();
    }

    private function month(mixed $value): string
    {
        $tz = (string) config('app.timezone');

        if (! is_string($value) || $value === '') {
            return CarbonImmutable::now($tz)->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value, $tz);

        if ($month === null || $month->greaterThan(CarbonImmutable::now($tz)->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'Choose this month or an earlier one.']);
        }

        return $month->format('Y-m');
    }

    /**
     * @param  class-string<Incident|CoverageReport>  $class
     */
    private function record(string $class, mixed $ulid, User $user, string $field): string
    {
        $record = is_string($ulid) ? $class::query()->where('ulid', $ulid)->first() : null;

        if ($record === null || ! $user->can('view', $record)) {
            throw ValidationException::withMessages([$field => 'Choose a record you can see.']);
        }

        return $record->ulid;
    }

    private function lga(mixed $ulid, User $user): string
    {
        $lga = is_string($ulid) ? Lga::query()->where('ulid', $ulid)->first() : null;

        if ($lga === null || ! $user->can('view', $lga)) {
            throw ValidationException::withMessages(['lga' => 'Choose an LGA within your jurisdiction.']);
        }

        return $lga->ulid;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function analytics(array $input, User $user): array
    {
        $tab = (string) ($input['tab'] ?? 'incidents');

        if (app(Analytics::class)->area($user, $tab) === null) {
            throw ValidationException::withMessages(['tab' => 'Choose an analytics area you can see.']);
        }

        return ['tab' => $tab, 'filters' => AnalyticsFilters::from((array) ($input['filters'] ?? []), $user)->values()];
    }
}
