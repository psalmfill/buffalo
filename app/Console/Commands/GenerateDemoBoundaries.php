<?php

namespace App\Console\Commands;

use App\Support\Geo\BoundaryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL ONLY (Phase 6A.0): gives every LGA a synthetic square boundary so
 * maps, centroids and boundary checks can be exercised before the owner
 * supplies real boundaries. Refuses to run outside the `local` environment.
 *
 * The squares are obviously fake: each state's LGAs form a grid of
 * DEMO_CELL-degree squares around a rough state centre. The import is
 * recorded as synthetic and its attribution says so on every map. State
 * boundaries are derived from the LGA squares.
 */
class GenerateDemoBoundaries extends Command
{
    protected $signature = 'buffalo:generate-demo-boundaries';

    protected $description = 'LOCAL ONLY: generate synthetic square LGA boundaries';

    public const ATTRIBUTION = 'SYNTHETIC demo boundaries (not real LGA shapes)';

    /** Size of each demo LGA square, in degrees. */
    private const DEMO_CELL = 0.15;

    /** Rough state centres (synthetic placement only), as in buffalo:seed-demo-incidents. */
    private const CENTRES = ['LA' => [6.52, 3.37], 'KN' => [12.0, 8.52], 'FC' => [9.07, 7.40], 'RI' => [4.82, 7.03]];

    public function handle(BoundaryImporter $importer): int
    {
        if (! app()->environment('local')) {
            $this->components->error('buffalo:generate-demo-boundaries runs only in the local environment.');

            return self::FAILURE;
        }

        $lgas = DB::table('lgas')->join('states', 'states.id', '=', 'lgas.state_id')
            ->orderBy('states.code')->orderBy('lgas.code')
            ->get(['lgas.id', 'states.id as state_id', 'states.code as state_code']);

        if ($lgas->isEmpty()) {
            $this->components->error('There are no LGAs to give boundaries to.');

            return self::FAILURE;
        }

        $geometries = [];

        foreach ($lgas->groupBy('state_code') as $stateCode => $stateLgas) {
            [$lat, $lng] = self::CENTRES[$stateCode] ?? $this->fallbackCentre((int) $stateLgas->first()->state_id);
            $columns = (int) ceil(sqrt($stateLgas->count()));
            $rows = (int) ceil($stateLgas->count() / $columns);
            $south = $lat - $rows * self::DEMO_CELL / 2;
            $west = $lng - $columns * self::DEMO_CELL / 2;

            foreach ($stateLgas->values() as $i => $lga) {
                $s = $south + intdiv($i, $columns) * self::DEMO_CELL;
                $w = $west + ($i % $columns) * self::DEMO_CELL;
                $n = $s + self::DEMO_CELL;
                $e = $w + self::DEMO_CELL;

                $prepared = $importer->prepare(['type' => 'Polygon', 'coordinates' => [[[$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s]]]]);

                if (is_string($prepared)) {
                    $this->components->error("{$stateCode}: {$prepared}");

                    return self::FAILURE;
                }

                $geometries[(int) $lga->id] = $prepared['ewkb'];
            }
        }

        $result = $importer->write('lga', $geometries, 'buffalo:generate-demo-boundaries', self::ATTRIBUTION, true, null);

        $this->components->warn('SYNTHETIC boundaries: '.count($geometries)." LGA squares written, {$result['derived_states']} state boundaries derived. Replace them with buffalo:import-boundaries.");
        ImportBoundaries::report($this, $importer);

        return self::SUCCESS;
    }

    /**
     * A deterministic spot inside Nigeria for states without a listed centre.
     *
     * @return array{0: float, 1: float}
     */
    private function fallbackCentre(int $stateId): array
    {
        return [7.0 + ($stateId * 37 % 50) / 10, 4.5 + ($stateId * 53 % 80) / 10];
    }
}
