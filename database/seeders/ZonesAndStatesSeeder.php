<?php

namespace Database\Seeders;

use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Support\Csv\CsvReader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the 6 geopolitical zones and 37 states from
 * database/data/zones_states.csv (authoritative, from the project owner).
 * Upserts by code, so it is safe to run repeatedly. Capitals are not in the
 * file and are left unset rather than guessed.
 */
class ZonesAndStatesSeeder extends Seeder
{
    public function __construct(private readonly CsvReader $reader) {}

    public function run(): void
    {
        $file = $this->reader->read(database_path('data/zones_states.csv'), ['zone_code', 'zone_name', 'state_code', 'state_name']);
        $errors = $file->errors;

        /** @var array<string, string> $zones */
        $zones = [];
        /** @var array<string, array{zone: string, name: string}> $states */
        $states = [];

        foreach ($file->rows as ['line' => $line, 'values' => $row]) {
            if (in_array('', $row, true)) {
                $errors[] = "{$file->name}:{$line}: every column is required.";

                continue;
            }

            if (isset($zones[$row['zone_code']]) && $zones[$row['zone_code']] !== $row['zone_name']) {
                $errors[] = "{$file->name}:{$line}: zone {$row['zone_code']} has two different names.";
            }

            if (isset($states[$row['state_code']])) {
                $errors[] = "{$file->name}:{$line}: duplicate state_code {$row['state_code']}.";
            }

            $zones[$row['zone_code']] = $row['zone_name'];
            $states[$row['state_code']] = ['zone' => $row['zone_code'], 'name' => $row['state_name']];
        }

        if ($errors !== []) {
            throw new RuntimeException("zones_states.csv is invalid:\n".implode("\n", $errors));
        }

        DB::transaction(function () use ($zones, $states): void {
            $zoneIds = [];

            foreach ($zones as $code => $name) {
                $zone = GeopoliticalZone::query()->firstOrNew(['code' => $code]);
                $zone->fill(['name' => $name])->save();
                $zoneIds[$code] = $zone->id;
            }

            foreach ($states as $code => $state) {
                State::query()->firstOrNew(['code' => $code])
                    ->fill(['name' => $state['name'], 'geopolitical_zone_id' => $zoneIds[$state['zone']]])
                    ->save();
            }
        });

        $this->command->info(sprintf('Seeded %d geopolitical zones and %d states.', count($zones), count($states)));
    }
}
