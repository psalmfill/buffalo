<?php

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentHierarchySeeder;
use Database\Seeders\ZonesAndStatesSeeder;

beforeEach(function () {
    $this->seed(ZonesAndStatesSeeder::class);
});

test('it refuses to run outside the local environment', function () {
    expect(app()->environment())->toBe('testing');

    expect(fn () => $this->seed(DevelopmentHierarchySeeder::class))
        ->toThrow(RuntimeException::class, 'only runs when APP_ENV=local');

    expect(SenatorialDistrict::count())->toBe(0)
        ->and(Lga::count())->toBe(0);
});

test('in local it creates synthetic Lagos and Kano data only, idempotently', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->seed(DevelopmentHierarchySeeder::class);
    $this->seed(DevelopmentHierarchySeeder::class);

    expect(SenatorialDistrict::count())->toBe(6)
        ->and(Lga::count())->toBe(24)
        ->and(Lga::query()->with('state')->get()->pluck('state.code')->unique()->sort()->values()->all())->toBe(['KN', 'LA']);

    $lga = Lga::query()->whereHas('state', fn ($q) => $q->where('code', 'LA'))->where('code', 'D23')->sole();

    expect($lga->name)->toBe('Demo LGA LA-2-3')
        ->and($lga->senatorialDistrict->code)->toBe('LA-D2')
        ->and($lga->senatorialDistrict->name)->toBe('Demo District LA-2');
});

test('the database seeder fails with a clear message outside local when the CSVs are missing', function () {
    expect(fn () => $this->seed(DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'senatorial_districts.csv and lgas.csv are missing');

    expect(Lga::count())->toBe(0);
});

test('the database seeder uses synthetic data in local when the CSVs are missing', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->seed(DatabaseSeeder::class);

    expect(Lga::count())->toBe(24);
});
