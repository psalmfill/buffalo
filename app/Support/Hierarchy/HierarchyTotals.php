<?php

namespace App\Support\Hierarchy;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;

/**
 * National totals of active hierarchy entries against the expected counts
 * (37 states, 109 senatorial districts, 774 LGAs), the same check the CSV
 * import makes. A difference is a warning, never a block: wards have no
 * fixed total.
 */
final class HierarchyTotals
{
    /**
     * @return array{states: array{active: int, expected: int}, districts: array{active: int, expected: int}, lgas: array{active: int, expected: int}}
     */
    public static function current(): array
    {
        return [
            'states' => ['active' => State::query()->active()->count(), 'expected' => HierarchyRules::EXPECTED_STATES],
            'districts' => ['active' => SenatorialDistrict::query()->active()->count(), 'expected' => HierarchyRules::EXPECTED_DISTRICTS],
            'lgas' => ['active' => Lga::query()->active()->count(), 'expected' => HierarchyRules::EXPECTED_LGAS],
        ];
    }

    /**
     * @return list<string>
     */
    public static function warnings(): array
    {
        $labels = ['states' => 'states', 'districts' => 'senatorial districts', 'lgas' => 'LGAs'];
        $warnings = [];

        foreach (self::current() as $key => ['active' => $active, 'expected' => $expected]) {
            if ($active !== $expected) {
                $warnings[] = "There are {$active} active {$labels[$key]} nationally; the expected total is {$expected}.";
            }
        }

        return $warnings;
    }
}
