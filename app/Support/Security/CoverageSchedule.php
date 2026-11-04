<?php

namespace App\Support\Security;

use App\Enums\CoveragePeriodType;
use App\Enums\CoverageReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * The coverage report LGAs are expected to submit (Phase 4A), from
 * config('buffalo.coverage'): the period type, the current and last
 * completed periods, and when a missing report becomes overdue.
 *
 * Since Phase 11G an LGA has reported for a period when any security
 * report (any period type, ad hoc included) was submitted during it, or a
 * report for exactly that period was submitted (as before).
 */
final class CoverageSchedule
{
    public function periodType(): CoveragePeriodType
    {
        $type = CoveragePeriodType::tryFrom((string) config('buffalo.coverage.expected_period', 'monthly'));

        if ($type === null || $type === CoveragePeriodType::AdHoc) {
            throw new InvalidArgumentException('buffalo.coverage.expected_period must be weekly, monthly or quarterly.');
        }

        return $type;
    }

    public function graceDays(): int
    {
        return max(0, (int) config('buffalo.coverage.grace_days', 5));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function currentPeriod(?CarbonImmutable $today = null): array
    {
        /** @var array{0: CarbonImmutable, 1: CarbonImmutable} */
        return $this->periodType()->periodContaining($today ?? CarbonImmutable::today());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function lastCompletedPeriod(?CarbonImmutable $today = null): array
    {
        /** @var array{0: CarbonImmutable, 1: CarbonImmutable} */
        return $this->periodType()->lastCompletedPeriod($today ?? CarbonImmutable::today());
    }

    /** The date a report for the period is due: its end plus the grace days. */
    public function dueDate(CarbonImmutable $periodEnd): CarbonImmutable
    {
        return $periodEnd->addDays($this->graceDays());
    }

    /**
     * Limits a coverage_reports query (Eloquent or base) to reports that
     * count for the period: submitted or reviewed, and either submitted
     * during the period or written for exactly that period.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function countingFor(QueryBuilder $query, CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $type = $this->periodType();

        $query->whereIn('coverage_reports.status', [CoverageReportStatus::Submitted->value, CoverageReportStatus::Reviewed->value])
            ->where(fn (QueryBuilder $q) => $q
                ->where(fn (QueryBuilder $q) => $q->where('coverage_reports.period_type', $type->value)->where('coverage_reports.period_start', $start->toDateString()))
                ->orWhere(fn (QueryBuilder $q) => $q->where('coverage_reports.submitted_at', '>=', $start->startOfDay())->where('coverage_reports.submitted_at', '<', $end->addDay()->startOfDay())));

        return $query;
    }

    /**
     * Limits a coverage_reports query to the reports shown for the period on
     * the dashboards: any report written for exactly the period (drafts
     * included), or submitted during it.
     *
     * @template TQuery of QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function relevantTo(QueryBuilder $query, CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $type = $this->periodType();

        $query->where(fn (QueryBuilder $q) => $q
            ->where(fn (QueryBuilder $q) => $q->where('coverage_reports.period_type', $type->value)->where('coverage_reports.period_start', $start->toDateString()))
            ->orWhere(fn (QueryBuilder $q) => $q->where('coverage_reports.submitted_at', '>=', $start->startOfDay())->where('coverage_reports.submitted_at', '<', $end->addDay()->startOfDay())));

        return $query;
    }
}
