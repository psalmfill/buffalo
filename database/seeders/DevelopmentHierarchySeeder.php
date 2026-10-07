<?php

namespace Database\Seeders;

use App\Actions\Hierarchy\ReadHierarchyFiles;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Support\GoLive\DemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SYNTHETIC hierarchy for local development only, used until the project
 * owner supplies senatorial_districts.csv and lgas.csv.
 *
 * Creates 3 districts per state and 4 LGAs per district for Lagos and Kano,
 * with obviously fake names ("Demo District LA-1", "Demo LGA LA-1-1") and
 * codes (districts LA-D1..LA-D3, LGAs D11..D34) that cannot be mistaken for
 * real data. Idempotent.
 */
class DevelopmentHierarchySeeder extends Seeder
{
    public const STATES = ['LA', 'KN'];

    public const DISTRICTS_PER_STATE = 3;

    public const LGAS_PER_DISTRICT = 4;

    public function run(): void
    {
        if (! DemoData::allowed()) {
            throw new RuntimeException(sprintf(
                'DevelopmentHierarchySeeder creates synthetic data and only runs when APP_ENV is local or uat (current: %s).',
                app()->environment(),
            ));
        }

        if (is_file(database_path('data/'.ReadHierarchyFiles::LGAS_FILE))) {
            $this->command->warn('database/data/lgas.csv exists: skipping synthetic hierarchy. Use php artisan buffalo:import-hierarchy.');

            return;
        }

        DB::transaction(function (): void {
            foreach (self::STATES as $stateCode) {
                $state = State::query()->where('code', $stateCode)->first()
                    ?? throw new RuntimeException("State {$stateCode} not found. Run ZonesAndStatesSeeder first.");

                for ($d = 1; $d <= self::DISTRICTS_PER_STATE; $d++) {
                    $district = SenatorialDistrict::query()->updateOrCreate(
                        ['code' => "{$stateCode}-D{$d}"],
                        ['state_id' => $state->id, 'name' => "Demo District {$stateCode}-{$d}", 'is_active' => true],
                    );

                    for ($l = 1; $l <= self::LGAS_PER_DISTRICT; $l++) {
                        Lga::query()->updateOrCreate(
                            ['state_id' => $state->id, 'code' => "D{$d}{$l}"],
                            [
                                'senatorial_district_id' => $district->id,
                                'name' => "Demo LGA {$stateCode}-{$d}-{$l}",
                                'is_active' => true,
                            ],
                        );
                    }
                }

                // Local development: the demo states are live, so their demo
                // officers can sign in (Phase 9B.3). The UAT environment sets
                // its own rollout.
                if (app()->environment('local') && ! $state->is_live) {
                    $state->forceFill(['is_live' => true, 'went_live_at' => now()])->save();
                }
            }
        });

        $districts = count(self::STATES) * self::DISTRICTS_PER_STATE;
        $lgas = $districts * self::LGAS_PER_DISTRICT;

        $this->command->warn(str_repeat('!', 78));
        $this->command->warn('SYNTHETIC HIERARCHY DATA IN USE (local development only).');
        $this->command->warn("Created {$districts} demo senatorial districts and {$lgas} demo LGAs for Lagos and Kano.");
        $this->command->warn('These are NOT real places. Supply database/data/senatorial_districts.csv and');
        $this->command->warn('lgas.csv (see database/data/README.md) and run buffalo:import-hierarchy.');
        $this->command->warn(str_repeat('!', 78));
    }
}
