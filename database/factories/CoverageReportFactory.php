<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\CoveragePeriodType;
use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\Lga;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoverageReport>
 */
class CoverageReportFactory extends Factory
{
    public function definition(): array
    {
        $month = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'period_type' => CoveragePeriodType::Monthly,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'status' => CoverageReportStatus::Draft,
            'classification' => Classification::Restricted,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function forMonth(CarbonImmutable $month): static
    {
        return $this->state([
            'period_type' => CoveragePeriodType::Monthly,
            'period_start' => $month->startOfMonth()->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
        ]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
