<?php

namespace Tests\Support\GoLive;

use App\Models\State;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\HierarchyFixtures;

/**
 * Production-shaped data for the go-live tests (Phase 9B): the full-size
 * synthetic hierarchy fixture (37 states, 109 districts, 774 LGAs, names
 * that do not look like demo data) and a small square boundary for every
 * LGA from a non-synthetic source.
 */
final class GoLiveFixtures
{
    public static function loadProductionHierarchy(): void
    {
        $directory = HierarchyFixtures::fullSizeDirectory(State::query()->orderBy('code')->pluck('code')->all());

        if (Artisan::call('buffalo:import-hierarchy', ['--path' => $directory]) !== 0) {
            throw new \RuntimeException('Hierarchy fixture import failed: '.Artisan::output());
        }

        DB::statement('UPDATE lgas SET boundary = ST_Multi(ST_MakeEnvelope(3 + (id % 100) * 0.02, 5 + (id / 100) * 0.02, 3.01 + (id % 100) * 0.02, 5.01 + (id / 100) * 0.02, 4326))');
        DB::table('boundary_sources')->insert([
            'level' => 'lga', 'source_name' => 'owner-lgas.geojson', 'attribution' => 'Test fixture boundaries',
            'synthetic' => false, 'feature_count' => 774, 'imported_at' => now(),
        ]);
    }
}
