<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\CoveragePeriodType;
use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Coverage reports (Phase 4A list, 11E export).
 */
final class CoverageReportsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'coverage_reports';
    }

    public function label(): string
    {
        return 'Coverage reports';
    }

    public function page(): string
    {
        return 'coverage-reports.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', CoverageReport::class);
    }

    public function rules(User $user): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'period_type' => ['nullable', Rule::enum(CoveragePeriodType::class)],
            'period' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(CoverageReportStatus::class)],
            'rating' => ['nullable', Rule::enum(CoverageRating::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CoverageReport>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;

        return CoverageReport::query()
            ->when($state, fn (Builder $q) => $q->where('coverage_reports.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'coverage_reports.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('coverage_reports.lga_id', $lga?->id))
            ->when($filters['period_type'] ?? null, fn (Builder $q, string $type) => $q->where('coverage_reports.period_type', $type))
            // A date within the period.
            ->when($filters['period'] ?? null, fn (Builder $q, string $date) => $q->where('coverage_reports.period_start', '<=', $date)->where('coverage_reports.period_end', '>=', $date))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('coverage_reports.status', $status))
            ->when($filters['rating'] ?? null, fn (Builder $q, string $rating) => $q->where('coverage_reports.overall_rating', $rating))
            ->orderByDesc('coverage_reports.period_start')
            ->orderBy('coverage_reports.id');
    }

    public function classificationColumn(): string
    {
        return 'coverage_reports.classification';
    }

    public function with(): array
    {
        return ['state', 'lga'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('lga', 'LGA', fn (CoverageReport $r) => $r->lga->name),
            ListColumn::make('state', 'State', fn (CoverageReport $r) => $r->state->name),
            ListColumn::make('period_type', 'Period type', fn (CoverageReport $r) => $r->period_type->label()),
            ListColumn::make('period', 'Period', fn (CoverageReport $r) => $r->periodLabel()),
            ListColumn::make('period_start', 'Period start', fn (CoverageReport $r) => self::date($r->period_start)),
            ListColumn::make('period_end', 'Period end', fn (CoverageReport $r) => self::date($r->period_end)),
            ListColumn::make('status', 'Status', fn (CoverageReport $r) => $r->status->label()),
            ListColumn::make('rating', 'Overall rating', fn (CoverageReport $r) => $r->overall_rating?->label()),
            ListColumn::make('submitted_at', 'Submitted', fn (CoverageReport $r) => self::at($r->submitted_at)),
            ListColumn::make('reviewed_at', 'Reviewed', fn (CoverageReport $r) => self::at($r->reviewed_at)),
            ListColumn::make('summary', 'Summary', fn (CoverageReport $r) => self::text($r, 'summary')),
            ListColumn::make('gaps', 'Gaps identified', fn (CoverageReport $r) => self::text($r, 'gaps_identified')),
            ListColumn::make('recommendations', 'Recommendations', fn (CoverageReport $r) => self::text($r, 'recommendations')),
            ListColumn::make('classification', 'Classification', fn (CoverageReport $r) => $r->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['lga', 'state', 'period_type', 'period', 'status', 'rating', 'submitted_at', 'classification'];
    }
}
