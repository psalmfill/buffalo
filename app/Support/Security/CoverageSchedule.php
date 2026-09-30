<?php

namespace App\Support\Security;

use App\Enums\CoveragePeriodType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The coverage report LGAs are expected to submit (Phase 4A), from
 * config('buffalo.coverage'): the period type, the current and last
 * completed periods, and when a missing report becomes overdue.
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
}
