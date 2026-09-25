<?php

use App\Actions\Hierarchy\HierarchyImportReport;
use App\Actions\Hierarchy\ImportHierarchy;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\Ward;
use Database\Seeders\DevelopmentHierarchySeeder;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\HierarchyFixtures;

beforeEach(function () {
    $this->seed(ZonesAndStatesSeeder::class);
});

afterEach(function () {
    HierarchyFixtures::cleanUp();
});

/**
 * Snapshot of every hierarchy row below the state level, to prove a failed
 * import leaves the database untouched.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function hierarchySnapshot(): array
{
    return collect(['senatorial_districts', 'lgas', 'wards'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
        ->all();
}

function importFixture(string $directory, bool $dryRun = false, bool $allowCountMismatch = true, bool $deactivateMissing = false): HierarchyImportReport
{
    return app(ImportHierarchy::class)($directory, $dryRun, $allowCountMismatch, $deactivateMissing);
}

test('valid files import successfully and a second run changes nothing', function () {
    $directory = HierarchyFixtures::directory();

    $this->artisan('buffalo:import-hierarchy', ['--path' => $directory, '--allow-count-mismatch' => true])
        ->expectsOutputToContain('Import complete.')
        ->expectsTable(
            ['Level', 'Created', 'Updated', 'Unchanged', 'Missing from files', 'Deactivated'],
            [
                ['Senatorial districts', 3, 0, 0, 0, 0],
                ['LGAs', 4, 0, 0, 0, 0],
                ['Wards', 3, 0, 0, 0, 0],
            ],
        )
        ->assertSuccessful();

    $lga = Lga::query()->where('code', 'FAB')->sole();

    expect(SenatorialDistrict::count())->toBe(3)
        ->and(Lga::count())->toBe(4)
        ->and(Ward::count())->toBe(3)
        ->and($lga->name)->toBe('Fixture LGA Two, East')
        ->and($lga->headquarters)->toBeNull()
        ->and($lga->state->code)->toBe('LA')
        ->and($lga->senatorialDistrict->code)->toBe('LA-A')
        ->and(Lga::query()->where('code', 'FAA')->count())->toBe(2)
        ->and(Ward::query()->where('code', 'W01')->pluck('state_id')->unique()->count())->toBe(2);

    $snapshot = hierarchySnapshot();

    $this->artisan('buffalo:import-hierarchy', ['--path' => $directory, '--allow-count-mismatch' => true])
        ->expectsTable(
            ['Level', 'Created', 'Updated', 'Unchanged', 'Missing from files', 'Deactivated'],
            [
                ['Senatorial districts', 0, 0, 3, 0, 0],
                ['LGAs', 0, 0, 4, 0, 0],
                ['Wards', 0, 0, 3, 0, 0],
            ],
        )
        ->assertSuccessful();

    expect(hierarchySnapshot())->toBe($snapshot);
});

test('a full-size file set imports without --allow-count-mismatch', function () {
    $directory = HierarchyFixtures::fullSizeDirectory(State::query()->orderBy('code')->pluck('code')->all());

    $this->artisan('buffalo:import-hierarchy', ['--path' => $directory])
        ->doesntExpectOutputToContain('Expected')
        ->assertSuccessful();

    expect(SenatorialDistrict::count())->toBe(109)
        ->and(Lga::count())->toBe(774);
});

test('each validation rule rejects its fixture and leaves the database untouched', function (string $fixture, string $expected) {
    importFixture(HierarchyFixtures::directory());
    $snapshot = hierarchySnapshot();

    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory("invalid/{$fixture}"), '--allow-count-mismatch' => true])
        ->expectsOutputToContain('Nothing was written.')
        ->expectsOutputToContain($expected)
        ->assertFailed();

    expect(hierarchySnapshot())->toBe($snapshot);
})->with([
    'header missing a column' => ['header-missing-column', 'lgas.csv:1: missing header column(s): headquarters'],
    'header with unknown column' => ['header-unknown-column', 'senatorial_districts.csv:1: unknown header column(s): notes'],
    'missing required field' => ['missing-required-field', 'lgas.csv:3: lga_name is required.'],
    'wrong number of fields' => ['wrong-field-count', 'lgas.csv:3: expected 5 fields but found 6'],
    'not UTF-8' => ['not-utf8', 'lgas.csv: file is not valid UTF-8.'],
    'unknown state code' => ['unknown-state', 'senatorial_districts.csv:5: unknown state_code "XX".'],
    'district code format' => ['bad-district-code', 'senatorial_districts.csv:5: district_code "LA-C" must be the state code'],
    'duplicate district code' => ['duplicate-district-code', 'senatorial_districts.csv:5: duplicate district_code "LA-A" (first used on line 2).'],
    'duplicate district name' => ['duplicate-district-name', 'senatorial_districts.csv:5: duplicate district_name "fixture district alpha" in state LA'],
    'LGA code format' => ['bad-lga-code', 'lgas.csv:3: lga_code "FA1" must be exactly 3 uppercase letters.'],
    'district not in file' => ['district-not-found', 'lgas.csv:3: district_code "LA-Z" is not in senatorial_districts.csv.'],
    'district in another state' => ['district-other-state', 'lgas.csv:3: district_code "KN-A" belongs to state KN, not LA.'],
    'duplicate LGA code in state' => ['duplicate-lga-code', 'lgas.csv:3: duplicate lga_code "FAA" in state LA (first used on line 2).'],
    'duplicate LGA name in state' => ['duplicate-lga-name', 'lgas.csv:3: duplicate lga_name "FIXTURE LGA ONE" in state LA'],
    'ward LGA not in file' => ['ward-lga-not-found', 'wards.csv:3: lga_code "FZZ" is not an LGA of state LA in lgas.csv.'],
    'ward code format' => ['bad-ward-code', 'wards.csv:3: ward_code "w 02" must be 1-20 uppercase letters'],
    'duplicate ward code in LGA' => ['duplicate-ward-code', 'wards.csv:3: duplicate ward_code "W01" in LGA LA/FAA'],
]);

test('all validation errors are reported together', function () {
    $report = importFixture(HierarchyFixtures::directory('invalid/multiple-errors'));

    expect($report->hasErrors())->toBeTrue()
        ->and($report->errors)->toContain(
            'lgas.csv:3: lga_code "fab" must be exactly 3 uppercase letters.',
            'lgas.csv:4: unknown state_code "XX".',
            'lgas.csv:4: district_code "LA-B" belongs to state LA, not XX.',
            'lgas.csv:5: district_code "LA-Z" is not in senatorial_districts.csv.',
        )
        ->and(Lga::count())->toBe(0);
});

test('a missing required file is an error', function () {
    $directory = HierarchyFixtures::directory();
    unlink("{$directory}/lgas.csv");

    $this->artisan('buffalo:import-hierarchy', ['--path' => $directory, '--allow-count-mismatch' => true])
        ->expectsOutputToContain('lgas.csv: file not found or not readable.')
        ->assertFailed();

    expect(SenatorialDistrict::count())->toBe(0);
});

test('wards.csv is optional', function () {
    $directory = HierarchyFixtures::directory();
    unlink("{$directory}/wards.csv");

    $this->artisan('buffalo:import-hierarchy', ['--path' => $directory, '--allow-count-mismatch' => true])
        ->expectsOutputToContain('wards.csv not found: wards were not imported.')
        ->assertSuccessful();

    expect(Lga::count())->toBe(4)->and(Ward::count())->toBe(0);
});

test('a count mismatch fails without the flag', function () {
    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory()])
        ->expectsOutputToContain('Expected 109 senatorial districts in senatorial_districts.csv, found 3. Use --allow-count-mismatch to import anyway.')
        ->expectsOutputToContain('Expected 774 LGAs in lgas.csv, found 4.')
        ->assertFailed();

    expect(SenatorialDistrict::count())->toBe(0);
});

test('a count mismatch is only a warning with --allow-count-mismatch', function () {
    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory(), '--allow-count-mismatch' => true])
        ->expectsOutputToContain('Expected 109 senatorial districts in senatorial_districts.csv, found 3. Continuing because --allow-count-mismatch was given.')
        ->assertSuccessful();

    expect(SenatorialDistrict::count())->toBe(3);
});

test('--dry-run reports what would happen and writes nothing', function () {
    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory(), '--allow-count-mismatch' => true, '--dry-run' => true])
        ->expectsOutputToContain('dry run: nothing will be written')
        ->expectsTable(
            ['Level (dry run)', 'Created', 'Updated', 'Unchanged', 'Missing from files', 'Deactivated'],
            [
                ['Senatorial districts', 3, 0, 0, 0, 0],
                ['LGAs', 4, 0, 0, 0, 0],
                ['Wards', 3, 0, 0, 0, 0],
            ],
        )
        ->expectsOutputToContain('Dry run complete. Nothing was written.')
        ->assertSuccessful();

    expect(SenatorialDistrict::count())->toBe(0)
        ->and(Lga::count())->toBe(0)
        ->and(Ward::count())->toBe(0);
});

test('--dry-run on existing data reports updates without applying them', function () {
    importFixture(HierarchyFixtures::directory());
    $snapshot = hierarchySnapshot();

    $renamed = importFixture(HierarchyFixtures::directory('renamed'), dryRun: true);
    $reduced = importFixture(HierarchyFixtures::directory('reduced'), dryRun: true, deactivateMissing: true);

    expect($renamed->counts['LGAs'])->toBe(['created' => 0, 'updated' => 1, 'unchanged' => 3, 'missing' => 0, 'deactivated' => 0])
        ->and($reduced->counts['LGAs'])->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'missing' => 1, 'deactivated' => 1])
        ->and($reduced->counts['Wards'])->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'missing' => 1, 'deactivated' => 1])
        ->and($renamed->written || $reduced->written)->toBeFalse()
        ->and(hierarchySnapshot())->toBe($snapshot);
});

test('re-running with corrected names updates them', function () {
    importFixture(HierarchyFixtures::directory());
    $ulid = Lga::query()->whereHas('state', fn ($q) => $q->where('code', 'LA'))->where('code', 'FAA')->value('ulid');

    $report = importFixture(HierarchyFixtures::directory('renamed'));

    $lga = Lga::query()->where('ulid', $ulid)->sole();

    expect($report->counts['LGAs'])->toBe(['created' => 0, 'updated' => 1, 'unchanged' => 3, 'missing' => 0, 'deactivated' => 0])
        ->and($lga->name)->toBe('Fixture LGA One Corrected');
});

test('names can be swapped between two LGAs of a state in one import', function () {
    importFixture(HierarchyFixtures::directory());

    $report = importFixture(HierarchyFixtures::directory('swapped'));

    $names = Lga::query()->whereHas('state', fn ($q) => $q->where('code', 'LA'))->pluck('name', 'code')->all();

    expect($report->hasErrors())->toBeFalse()
        ->and($report->counts['LGAs']['updated'])->toBe(2)
        ->and($names['FAA'])->toBe('Fixture LGA Three')
        ->and($names['FBA'])->toBe('Fixture LGA One');
});

test('an LGA name still used by an LGA that stays in the database is rejected', function () {
    importFixture(HierarchyFixtures::directory());
    $snapshot = hierarchySnapshot();

    $report = importFixture(HierarchyFixtures::directory('name-conflict'));

    expect($report->errors)->toContain('lgas.csv:4: lga_name "Fixture LGA Three" is already used by LGA LA/FBA in the database, which is not in lgas.csv.')
        ->and(hierarchySnapshot())->toBe($snapshot);
});

test('records missing from the files are reported but left active by default', function () {
    importFixture(HierarchyFixtures::directory());

    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory('reduced'), '--allow-count-mismatch' => true])
        ->expectsOutputToContain('1 LGAs in the database is not in the files (left unchanged; use --deactivate-missing to deactivate them)')
        ->expectsOutputToContain('LA/FBA (Fixture LGA Three)')
        ->expectsOutputToContain('LA/FAA/W02 (Fixture Ward Two)')
        ->assertSuccessful();

    expect(Lga::query()->active()->count())->toBe(4)
        ->and(Ward::query()->active()->count())->toBe(3);
});

test('--deactivate-missing deactivates and never deletes', function () {
    importFixture(HierarchyFixtures::directory());

    $this->artisan('buffalo:import-hierarchy', [
        '--path' => HierarchyFixtures::directory('reduced'),
        '--allow-count-mismatch' => true,
        '--deactivate-missing' => true,
    ])
        ->expectsTable(
            ['Level', 'Created', 'Updated', 'Unchanged', 'Missing from files', 'Deactivated'],
            [
                ['Senatorial districts', 0, 0, 3, 0, 0],
                ['LGAs', 0, 0, 3, 1, 1],
                ['Wards', 0, 0, 2, 1, 1],
            ],
        )
        ->assertSuccessful();

    $lga = Lga::query()->whereHas('state', fn ($q) => $q->where('code', 'LA'))->where('code', 'FBA')->sole();

    expect(Lga::count())->toBe(4)
        ->and(Ward::count())->toBe(3)
        ->and($lga->is_active)->toBeFalse()
        ->and(Ward::query()->where('code', 'W02')->value('is_active'))->toBeFalse()
        ->and(Lga::query()->active()->count())->toBe(3);

    // Running again reports nothing new as missing: already inactive.
    $report = importFixture(HierarchyFixtures::directory('reduced'), deactivateMissing: true);

    expect($report->counts['LGAs'])->toBe(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'missing' => 0, 'deactivated' => 0]);
});

test('a deactivated record that reappears in the files is reactivated', function () {
    importFixture(HierarchyFixtures::directory());
    importFixture(HierarchyFixtures::directory('reduced'), deactivateMissing: true);

    $report = importFixture(HierarchyFixtures::directory());

    expect($report->counts['LGAs']['updated'])->toBe(1)
        ->and(Lga::query()->active()->count())->toBe(4);
});

test('synthetic development data is reported as missing when real files are imported', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->seed(DevelopmentHierarchySeeder::class);

    $report = importFixture(HierarchyFixtures::directory(), dryRun: true);

    expect($report->counts['LGAs']['missing'])->toBe(24)
        ->and($report->counts['Senatorial districts']['missing'])->toBe(6);
});
