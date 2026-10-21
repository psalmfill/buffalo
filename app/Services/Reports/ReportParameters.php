<?php

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Models\Arrest;
use App\Models\CoverageReport;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\ReportRun;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsFilters;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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
 * | list          | list (exporter key), filters (the page's), columns (allowed set) |
 * | flashpoint, organization_site, arrest, threat_report, criminal_group,  |
 * | person        | the record's ULID (flashpoint, site, arrest, threat, group, person) |
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
            ReportType::LgaProfile => ['lga' => $this->lga($input['lga'] ?? null, $user)],
            ReportType::Analytics => $this->analytics($input, $user),
            ReportType::ListExport => $this->listExport($input, $user),
            ReportType::Incident, ReportType::Coverage, ReportType::Flashpoint, ReportType::OrganizationSite, ReportType::Arrest,
            ReportType::ThreatReport, ReportType::CriminalGroup, ReportType::Person => $this->single($type, $input, $user),
        };
    }

    /** The model of each single-record type. */
    public static function recordClass(ReportType $type): ?string
    {
        return match ($type) {
            ReportType::Incident => Incident::class,
            ReportType::Coverage => CoverageReport::class,
            ReportType::Flashpoint => Flashpoint::class,
            ReportType::OrganizationSite => OrganizationSite::class,
            ReportType::Arrest => Arrest::class,
            ReportType::ThreatReport => ThreatReport::class,
            ReportType::CriminalGroup => CriminalGroup::class,
            ReportType::Person => Person::class,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function single(ReportType $type, array $input, User $user): array
    {
        $key = (string) $type->recordParameter();
        /** @var class-string<Model> $class */
        $class = (string) self::recordClass($type);

        return [$key => $this->record($class, $input[$key] ?? null, $user, $key)];
    }

    /**
     * A list export (Phase 11E): the exporter, the page's filters (its own
     * validation rules) and the chosen columns (from its allowed set).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listExport(array $input, User $user): array
    {
        $exporter = ListExporters::find((string) ($input['list'] ?? ''));

        if ($exporter === null || ! $user->can('exportList', [ReportRun::class, $exporter])) {
            throw ValidationException::withMessages(['list' => 'Choose a list you may export.']);
        }

        $filters = $exporter->filters((array) ($input['filters'] ?? []), $user);
        $allowed = array_keys($exporter->columnMap());
        $columns = array_values(array_unique(array_filter((array) ($input['columns'] ?? []), 'is_string')));

        if (array_diff($columns, $allowed) !== []) {
            throw ValidationException::withMessages(['columns' => 'Choose columns offered for this list.']);
        }

        return [
            'list' => $exporter->key(),
            'filters' => $filters,
            'columns' => $columns === [] ? $exporter->defaultColumns() : array_values(array_intersect($allowed, $columns)),
        ];
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
     * @param  class-string<Model>  $class
     */
    private function record(string $class, mixed $ulid, User $user, string $field): string
    {
        $record = is_string($ulid) ? $class::query()->where('ulid', $ulid)->first() : null;

        if ($record === null || ! $user->can('view', $record)) {
            throw ValidationException::withMessages([$field => 'Choose a record you can see.']);
        }

        return (string) $record->getAttribute('ulid');
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
