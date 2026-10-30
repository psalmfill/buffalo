<?php

use App\Actions\Hierarchy\ImportHierarchy;
use App\Models\Community;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Models\Ward;
use App\Support\Audit\AuditLog;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Database\Eloquent\Model;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\HierarchyFixtures;

/*
| Hierarchy administration (CRUD coverage step): super admins and the DG
| edit state capitals and create, rename and deactivate districts, LGAs and
| wards, with step-up and a reason; codes never change; the import's rules
| apply; the CSV import reports screen-created entries instead of
| deactivating them.
*/

beforeEach(function () {
    $this->seed(ZonesAndStatesSeeder::class);
    $this->lagos = State::query()->where('code', 'LA')->firstOrFail();
    $this->kano = State::query()->where('code', 'KN')->firstOrFail();
    $this->district = SenatorialDistrict::factory()->create(['state_id' => $this->lagos->id, 'code' => 'LA-C', 'name' => 'Lagos Central']);
    $this->lga = Lga::factory()->create(['senatorial_district_id' => $this->district->id, 'code' => 'IKJ', 'name' => 'Ikeja']);
    $this->ward = Ward::factory()->create(['lga_id' => $this->lga->id, 'code' => 'W01', 'name' => 'Alausa']);
    $this->dg = User::factory()->directorGeneral()->create();
    $this->admin = User::factory()->superAdmin()->create();
});

afterEach(function () {
    HierarchyFixtures::cleanUp();
});

function hierarchyEvent(string $event): ?Activity
{
    return Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('event', $event)->latest('id')->first();
}

test('super admins and the DG manage the hierarchy; everyone else is refused', function () {
    foreach ([$this->admin, $this->dg] as $user) {
        CrossFixtures::signIn($user)->get('/system/hierarchy')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('system/hierarchy/index')->has('states', 37));
        CrossFixtures::signIn($user)->get("/system/hierarchy/states/{$this->lagos->ulid}")->assertOk();
        CrossFixtures::signIn($user)->get("/system/hierarchy/lgas/{$this->lga->ulid}")->assertOk();
    }

    $others = [
        User::factory()->ddg()->create(),
        User::factory()->stateDirector($this->lagos)->create(),
        User::factory()->deputyStateDirector($this->lagos)->create(),
        User::factory()->lgaChief($this->lga)->create(),
    ];

    foreach ($others as $user) {
        CrossFixtures::signIn($user)->get('/system/hierarchy')->assertForbidden();
        CrossFixtures::signIn($user)->get("/system/hierarchy/states/{$this->lagos->ulid}")->assertForbidden();
        CrossFixtures::signIn($user)->post("/system/hierarchy/states/{$this->lagos->ulid}/lgas", [
            'district' => $this->district->ulid, 'code' => 'EPE', 'name' => 'Epe', 'reason' => 'Test',
        ])->assertForbidden();
        CrossFixtures::signIn($user)->put("/system/hierarchy/wards/{$this->ward->ulid}/active", ['active' => false, 'reason' => 'Test'])->assertForbidden();
    }

    expect(Lga::query()->where('code', 'EPE')->exists())->toBeFalse()
        ->and($this->ward->fresh()->is_active)->toBeTrue();
});

test('every change needs step-up and a reason', function () {
    CrossFixtures::signInWithoutStepUp($this->dg)
        ->put("/system/hierarchy/wards/{$this->ward->ulid}", ['name' => 'Alausa North', 'reason' => 'Renamed by INEC'], ['X-Inertia' => 'true'])
        ->assertStatus(428)
        ->assertJson(['step_up' => true]);

    CrossFixtures::signIn($this->dg)
        ->put("/system/hierarchy/wards/{$this->ward->ulid}", ['name' => 'Alausa North', 'reason' => '   '])
        ->assertSessionHasErrors('reason');

    expect($this->ward->fresh()->name)->toBe('Alausa');
});

test('a state capital is edited with a named audit event; states cannot be created or deleted', function () {
    CrossFixtures::signIn($this->admin)
        ->put("/system/hierarchy/states/{$this->lagos->ulid}/capital", ['capital' => 'Ikeja', 'reason' => 'Capital recorded from the gazette'])
        ->assertSessionHasNoErrors();

    $event = hierarchyEvent('state_capital_changed');
    expect($this->lagos->fresh()->capital)->toBe('Ikeja')
        ->and($event?->causer_id)->toBe($this->admin->id)
        ->and($event?->subject_id)->toBe($this->lagos->id)
        ->and($event?->properties['reason'])->toBe('Capital recorded from the gazette')
        ->and($event?->properties['after'])->toBe('Ikeja');

    CrossFixtures::signIn($this->admin)->post('/system/hierarchy/states', ['name' => 'New State'])->assertNotFound();
    CrossFixtures::signIn($this->admin)->delete("/system/hierarchy/states/{$this->lagos->ulid}")->assertMethodNotAllowed();
    expect(State::count())->toBe(37);
});

test('a district is created under the import rules and recorded as created on screen', function () {
    $post = fn (array $data) => CrossFixtures::signIn($this->dg)->post("/system/hierarchy/states/{$this->lagos->ulid}/districts", [...$data, 'reason' => 'New district gazetted']);

    $post(['code' => 'KN-E', 'name' => 'Lagos East'])->assertSessionHasErrors('code');   // another state's prefix
    $post(['code' => 'LA-e', 'name' => 'Lagos East'])->assertSessionHasErrors('code');   // lowercase
    $post(['code' => 'LA-C', 'name' => 'Lagos East'])->assertSessionHasErrors('code');   // taken
    $post(['code' => 'LA-E', 'name' => 'LAGOS central'])->assertSessionHasErrors('name'); // same name, any case
    expect(SenatorialDistrict::count())->toBe(1);

    $post(['code' => 'LA-E', 'name' => 'Lagos East'])->assertSessionHasNoErrors();

    $district = SenatorialDistrict::query()->where('code', 'LA-E')->sole();
    expect($district->origin)->toBe('admin')
        ->and($district->created_by)->toBe($this->dg->id)
        ->and($district->created_reason)->toBe('New district gazetted')
        ->and($district->state_id)->toBe($this->lagos->id)
        ->and(hierarchyEvent('senatorial_district_created')?->properties['reason'])->toBe('New district gazetted');
});

test('an LGA needs an active district of the same state and a unique code and name', function () {
    $kanoDistrict = SenatorialDistrict::factory()->create(['state_id' => $this->kano->id, 'code' => 'KN-C']);
    $closed = SenatorialDistrict::factory()->inactive()->create(['state_id' => $this->lagos->id, 'code' => 'LA-X']);
    $post = fn (array $data) => CrossFixtures::signIn($this->admin)->post("/system/hierarchy/states/{$this->lagos->ulid}/lgas", [
        'district' => $this->district->ulid, 'code' => 'EPE', 'name' => 'Epe', 'reason' => 'Created by law', ...$data,
    ]);

    $post(['district' => $kanoDistrict->ulid])->assertSessionHasErrors('district');
    $post(['district' => $closed->ulid])->assertSessionHasErrors('district');
    $post(['code' => 'EP'])->assertSessionHasErrors('code');
    $post(['code' => 'IKJ'])->assertSessionHasErrors('code');
    $post(['name' => 'IKEJA'])->assertSessionHasErrors('name');
    expect(Lga::count())->toBe(1);

    $post(['headquarters' => 'Epe Town'])->assertSessionHasNoErrors();

    $lga = Lga::query()->where('code', 'EPE')->sole();
    expect($lga->state_id)->toBe($this->lagos->id)
        ->and($lga->headquarters)->toBe('Epe Town')
        ->and($lga->origin)->toBe('admin')
        ->and(hierarchyEvent('lga_created')?->causer_id)->toBe($this->admin->id);

    // The same code is fine in another state.
    CrossFixtures::signIn($this->admin)->post("/system/hierarchy/states/{$this->kano->ulid}/lgas", [
        'district' => $kanoDistrict->ulid, 'code' => 'EPE', 'name' => 'Epe', 'reason' => 'Created by law',
    ])->assertSessionHasNoErrors();
});

test('a ward needs an active LGA and a unique code and name within it', function () {
    $post = fn (Lga $lga, array $data) => CrossFixtures::signIn($this->dg)->post("/system/hierarchy/lgas/{$lga->ulid}/wards", ['reason' => 'INEC ward list', ...$data]);

    $post($this->lga, ['code' => '-W2', 'name' => 'Oregun'])->assertSessionHasErrors('code');
    $post($this->lga, ['code' => 'W01', 'name' => 'Oregun'])->assertSessionHasErrors('code');
    $post($this->lga, ['code' => 'W02', 'name' => 'alausa'])->assertSessionHasErrors('name');
    $post(Lga::factory()->inactive()->create(['senatorial_district_id' => $this->district->id]), ['code' => 'W02', 'name' => 'Oregun'])->assertSessionHasErrors('lga');

    $post($this->lga, ['code' => 'W02', 'name' => 'Oregun'])->assertSessionHasNoErrors();

    $ward = Ward::query()->where('lga_id', $this->lga->id)->where('code', 'W02')->sole();
    expect($ward->state_id)->toBe($this->lagos->id)
        ->and($ward->origin)->toBe('admin')
        ->and(hierarchyEvent('ward_created')?->properties['reason'])->toBe('INEC ward list');
});

test('renaming keeps the code, stays unique within the parent and is audited with the old name', function () {
    Lga::factory()->create(['senatorial_district_id' => $this->district->id, 'code' => 'EPE', 'name' => 'Epe']);

    CrossFixtures::signIn($this->dg)->put("/system/hierarchy/lgas/{$this->lga->ulid}", ['name' => 'epe', 'reason' => 'Spelling'])
        ->assertSessionHasErrors('name');

    CrossFixtures::signIn($this->dg)->put("/system/hierarchy/lgas/{$this->lga->ulid}", [
        'name' => 'Ikeja Central', 'headquarters' => 'Alausa', 'code' => 'XYZ', 'reason' => 'Renamed by state law',
    ])->assertSessionHasNoErrors();

    $lga = $this->lga->fresh();
    $event = hierarchyEvent('lga_renamed');
    expect($lga->name)->toBe('Ikeja Central')
        ->and($lga->headquarters)->toBe('Alausa')
        ->and($lga->code)->toBe('IKJ') // codes never change
        ->and($event?->properties['before'])->toBe(['name' => 'Ikeja', 'headquarters' => null])
        ->and($event?->properties['reason'])->toBe('Renamed by state law');

    CrossFixtures::signIn($this->dg)->put("/system/hierarchy/districts/{$this->district->ulid}", ['name' => 'Lagos Central District', 'reason' => 'Official name'])
        ->assertSessionHasNoErrors();
    expect($this->district->fresh()->name)->toBe('Lagos Central District')
        ->and($this->district->fresh()->code)->toBe('LA-C');
});

test('deactivating never deletes, keeps existing records on the entry and respects parents and children', function () {
    $community = Community::factory()->in($this->lga)->create(['ward_id' => $this->ward->id]);
    $toggle = fn (string $level, Model $entry, bool $active) => CrossFixtures::signIn($this->dg)
        ->put("/system/hierarchy/{$level}/{$entry->ulid}/active", ['active' => $active, 'reason' => 'Boundary review']);

    $toggle('districts', $this->district, false)->assertSessionHasErrors('entry'); // has an active LGA
    $toggle('lgas', $this->lga, false)->assertSessionHasErrors('entry');           // has an active ward

    $toggle('wards', $this->ward, false)->assertSessionHasNoErrors();
    $toggle('lgas', $this->lga, false)->assertSessionHasNoErrors();
    $toggle('districts', $this->district, false)->assertSessionHasNoErrors();

    expect(Ward::query()->whereKey($this->ward->id)->value('is_active'))->toBeFalse()
        ->and(Lga::query()->whereKey($this->lga->id)->exists())->toBeTrue()
        ->and($community->fresh()->ward_id)->toBe($this->ward->id)
        ->and($community->fresh()->lga_id)->toBe($this->lga->id)
        ->and(hierarchyEvent('ward_deactivated')?->properties['reason'])->toBe('Boundary review');

    // Reactivation goes top-down.
    $toggle('wards', $this->ward, true)->assertSessionHasErrors('entry');
    $toggle('lgas', $this->lga, true)->assertSessionHasErrors('entry');
    $toggle('districts', $this->district, true)->assertSessionHasNoErrors();
    $toggle('lgas', $this->lga, true)->assertSessionHasNoErrors();
    $toggle('wards', $this->ward, true)->assertSessionHasNoErrors();
    expect(hierarchyEvent('ward_reactivated'))->not->toBeNull();
});

test('a change that moves the national totals away from 109 and 774 warns without blocking', function () {
    $response = CrossFixtures::signIn($this->dg)->post("/system/hierarchy/states/{$this->lagos->ulid}/lgas", [
        'district' => $this->district->ulid, 'code' => 'EPE', 'name' => 'Epe', 'reason' => 'Created by law',
    ]);

    $response->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'warning'
        && str_contains($toast['message'], 'There are 2 active LGAs nationally; the expected total is 774.'));
    expect(Lga::query()->where('code', 'EPE')->exists())->toBeTrue();

    CrossFixtures::signIn($this->dg)->get("/system/hierarchy/states/{$this->lagos->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('warnings', fn ($warnings) => collect($warnings)->contains(fn ($w) => str_contains($w, '774'))));
});

test('the pages list deactivated entries and who created entries on screen, and why', function () {
    CrossFixtures::signIn($this->dg)->post("/system/hierarchy/lgas/{$this->lga->ulid}/wards", ['code' => 'W02', 'name' => 'Oregun', 'reason' => 'INEC ward list']);
    Ward::factory()->inactive()->create(['lga_id' => $this->lga->id, 'code' => 'W03']);

    CrossFixtures::signIn($this->admin)->get("/system/hierarchy/lgas/{$this->lga->ulid}")
        ->assertInertia(fn (Assert $page) => $page->component('system/hierarchy/lga')
            ->has('wards', 3)
            ->where('wards', fn ($wards) => collect($wards)->firstWhere('code', 'W02')['created_by'] === $this->dg->name
                && collect($wards)->firstWhere('code', 'W02')['created_reason'] === 'INEC ward list'
                && collect($wards)->firstWhere('code', 'W01')['created_on_screen'] === false
                && collect($wards)->firstWhere('code', 'W03')['is_active'] === false));
});

test('the import reports screen-created entries missing from the files and never deactivates them', function () {
    CrossFixtures::signIn($this->dg)->post("/system/hierarchy/states/{$this->lagos->ulid}/lgas", [
        'district' => $this->district->ulid, 'code' => 'EPE', 'name' => 'Epe', 'reason' => 'Created by law',
    ])->assertSessionHasNoErrors();
    // Imported entries are not screen-created, whatever their history.
    $this->district->forceFill(['origin' => 'import'])->save();

    $dryRun = app(ImportHierarchy::class)(HierarchyFixtures::directory(), dryRun: true, allowCountMismatch: true, deactivateMissing: true);
    expect($dryRun->onScreen['LGAs'])->toHaveCount(1)
        ->and($dryRun->onScreen['LGAs'][0])->toMatchArray(['entry' => 'LA/EPE (Epe)', 'created_by' => $this->dg->name, 'reason' => 'Created by law'])
        ->and($dryRun->onScreen['LGAs'][0]['created_at'])->not->toBeNull();

    $this->artisan('buffalo:import-hierarchy', ['--path' => HierarchyFixtures::directory(), '--allow-count-mismatch' => true, '--deactivate-missing' => true])
        ->expectsOutputToContain('1 LGAs created on screen is not in the files (left active):')
        ->expectsOutputToContain("LA/EPE (Epe) — created by {$this->dg->name} on")
        ->expectsOutputToContain('Add these to the official CSV, or deactivate them in Administration → Hierarchy if they are no longer needed.')
        ->assertSuccessful();

    expect(Lga::query()->where('code', 'EPE')->value('is_active'))->toBeTrue()
        ->and($this->lga->fresh()->is_active)->toBeFalse(); // imported, missing from the files: deactivated as before
});

test('a screen-created entry that appears in the official files becomes an imported one', function () {
    $district = SenatorialDistrict::factory()->create(['state_id' => $this->lagos->id, 'code' => 'LA-A', 'name' => 'Typed on screen']);
    $district->forceFill(['origin' => 'admin', 'created_by' => $this->dg->id, 'created_reason' => 'Early entry'])->save();

    $report = app(ImportHierarchy::class)(HierarchyFixtures::directory(), allowCountMismatch: true);

    expect($report->hasErrors())->toBeFalse()
        ->and($district->fresh()->origin)->toBe('import')
        ->and($district->fresh()->name)->toBe('Fixture District Alpha')
        ->and($report->onScreen['Senatorial districts'])->toBe([]);
});
