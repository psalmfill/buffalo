<?php

use App\Console\DemoCommands\GenerateDemoBoundaries;
use App\Console\DemoCommands\SeedDemoIncidents;
use App\Models\Incident;
use App\Models\User;
use App\Support\GoLive\DataStatus;
use App\Support\GoLive\DemoData;
use App\Support\Jurisdiction\JurisdictionContext;
use Database\Seeders\DevelopmentHierarchySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\GoLive\GoLiveFixtures;

/*
| Production data status and demo-data detection (Phase 9B.1).
*/

beforeEach(function () {
    $this->seed([ZonesAndStatesSeeder::class, ReferenceDataSeeder::class]);
    app(JurisdictionContext::class)->system();
});

function demoKinds(): array
{
    return array_column(app(DataStatus::class)->demo(), 'count', 'kind');
}

test('an empty system reports the missing hierarchy and boundaries', function () {
    $status = app(DataStatus::class)->build();

    expect($status['hierarchy']['states'])->toBe(37)
        ->and($status['missing'])->toContain('Senatorial districts: 0 of 109 loaded', 'LGAs: 0 of 774 loaded')
        ->and(collect($status['missing'])->first(fn ($m) => str_starts_with($m, 'LGA boundaries')))->not->toBeNull();

    $this->artisan('buffalo:data:status')->assertFailed();
});

test('a fully prepared system is ready and the command passes', function () {
    GoLiveFixtures::loadProductionHierarchy();

    $status = app(DataStatus::class)->build();

    expect($status['hierarchy']['districts'])->toBe(109)
        ->and($status['hierarchy']['lgas'])->toBe(774)
        ->and($status['boundaries']['lgas_with_boundary'])->toBe(774)
        ->and($status['demo'])->toBe([])
        ->and(array_filter($status['missing'], fn ($m) => ! str_contains($m, '(optional)')))->toBe([]);

    $this->artisan('buffalo:data:status')
        ->expectsOutputToContain('No demo or synthetic data')
        ->assertSuccessful();
});

test('each kind of demo or synthetic data is detected', function () {
    GoLiveFixtures::loadProductionHierarchy();
    expect(demoKinds())->toBe([]);

    app()->detectEnvironment(fn () => 'local');
    $this->seed(DevelopmentHierarchySeeder::class);
    app()->detectEnvironment(fn () => 'testing');
    expect(demoKinds())->toHaveKeys(['demo_lgas', 'demo_districts']);

    DB::table('boundary_sources')->insert([
        'level' => 'lga', 'source_name' => 'x', 'attribution' => GenerateDemoBoundaries::ATTRIBUTION,
        'synthetic' => true, 'feature_count' => 1, 'imported_at' => now(),
    ]);
    expect(demoKinds())->toHaveKey('synthetic_boundaries');

    Incident::factory()->create(['title' => '[Demo] Synthetic incident 1']);
    expect(demoKinds()['demo_incidents'])->toBe(1);

    $this->artisan('buffalo:data:status')->assertFailed();
});

test('accounts on reserved test domains are detected, real domains are not', function () {
    User::factory()->create(['email' => 'officer@agency.gov.ng']);
    expect(demoKinds())->not->toHaveKey('test_accounts');

    foreach (['dg@buffalo.local', 'a@uat.buffalo.test', 'b@example.com', 'c@x.example', 'd@y.invalid'] as $email) {
        User::factory()->create(['email' => $email]);
    }

    expect(demoKinds()['test_accounts'])->toBe(5);
});

test('reference fingerprints change when a reference entry changes', function () {
    $status = app(DataStatus::class);
    $before = $status->referenceVersion();

    DB::table('threat_types')->where('is_active', true)->orderBy('code')->limit(1)->update(['name' => 'Renamed threat']);

    expect($status->referenceVersion())->not->toBe($before);
});

test('data status refuses to run in a user context', function () {
    app(JurisdictionContext::class)->user(User::factory()->create());

    app(DataStatus::class)->build();
})->throws(LogicException::class);

test('the demo generators are not auto-discovered and are registered only outside production', function () {
    expect(DemoData::REGISTERED_ENVIRONMENTS)->not->toContain('production')
        ->and(DemoData::GENERATOR_ENVIRONMENTS)->toBe(['local', 'uat'])
        ->and(glob(app_path('Console/Commands/*Demo*.php')))->toBe([])
        ->and(class_exists(GenerateDemoBoundaries::class) && class_exists(SeedDemoIncidents::class))->toBeTrue();

    $list = fn (string $env) => Process::path(base_path())
        ->env(['APP_ENV' => $env, 'APP_DEBUG' => 'false'])
        ->run(['php', 'artisan', 'list', '--raw'])->output();

    expect($list('production'))->not->toContain('buffalo:seed-demo-incidents')
        ->not->toContain('buffalo:generate-demo-boundaries')
        ->toContain('buffalo:data:status')
        ->and($list('uat'))->toContain('buffalo:seed-demo-incidents');
});
