<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Period a coverage report covers (Phase 4A). Weekly, monthly and quarterly
 * reports must match the period boundaries (Monday–Sunday, calendar month,
 * calendar quarter); ad hoc reports cover any range.
 */
enum CoveragePeriodType: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case AdHoc = 'ad_hoc';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::AdHoc => 'Ad hoc',
        };
    }

    /**
     * The period containing $date, or null for ad hoc.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function periodContaining(CarbonImmutable $date): ?array
    {
        $date = $date->startOfDay();

        return match ($this) {
            self::Weekly => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            self::Monthly => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()],
            self::Quarterly => [$date->startOfQuarter(), $date->endOfQuarter()->startOfDay()],
            self::AdHoc => null,
        };
    }

    /**
     * The last period that has fully ended before $today, or null for ad hoc.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function lastCompletedPeriod(CarbonImmutable $today): ?array
    {
        $current = $this->periodContaining($today);

        return $current === null ? null : $this->periodContaining($current[0]->subDay());
    }

    /** Whether the dates are exactly one period of this type. */
    public function matchesBoundaries(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $period = $this->periodContaining($start);

        return $period === null || ($period[0]->equalTo($start->startOfDay()) && $period[1]->equalTo($end->startOfDay()));
    }

    public function describe(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return match ($this) {
            self::Weekly => 'Week of '.$start->format('j M Y'),
            self::Monthly => $start->format('F Y'),
            self::Quarterly => 'Q'.$start->quarter.' '.$start->year,
            self::AdHoc => $start->format('j M Y').' – '.$end->format('j M Y'),
        };
    }
}
