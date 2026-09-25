<?php

use App\Models\GeopoliticalZone;
use App\Models\State;
use Database\Seeders\ZonesAndStatesSeeder;

test('it seeds 6 zones and 37 states, each in the zone given by the CSV', function () {
    $this->seed(ZonesAndStatesSeeder::class);

    expect(GeopoliticalZone::count())->toBe(6)
        ->and(State::count())->toBe(37);

    $rows = array_map('str_getcsv', array_slice(file(database_path('data/zones_states.csv'), FILE_IGNORE_NEW_LINES), 1));

    foreach ($rows as [$zoneCode, $zoneName, $stateCode, $stateName]) {
        $state = State::query()->where('code', $stateCode)->with('geopoliticalZone')->sole();

        expect($state->name)->toBe($stateName)
            ->and($state->geopoliticalZone->code)->toBe($zoneCode)
            ->and($state->geopoliticalZone->name)->toBe($zoneName)
            ->and($state->capital)->toBeNull();
    }

    expect(GeopoliticalZone::query()->withCount('states')->pluck('states_count', 'code')->all())
        ->toEqual(['NC' => 7, 'NE' => 6, 'NW' => 7, 'SE' => 5, 'SS' => 6, 'SW' => 6]);
});

test('running it twice creates no duplicates and keeps ULIDs', function () {
    $this->seed(ZonesAndStatesSeeder::class);
    $ulids = State::query()->pluck('ulid', 'code')->all();

    $this->seed(ZonesAndStatesSeeder::class);

    expect(GeopoliticalZone::count())->toBe(6)
        ->and(State::count())->toBe(37)
        ->and(State::query()->pluck('ulid', 'code')->all())->toBe($ulids);
});

test('it restores names changed in the database', function () {
    $this->seed(ZonesAndStatesSeeder::class);
    State::query()->where('code', 'LA')->update(['name' => 'Wrong']);

    $this->seed(ZonesAndStatesSeeder::class);

    expect(State::query()->where('code', 'LA')->value('name'))->toBe('Lagos');
});
