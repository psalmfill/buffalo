<?php

use App\Enums\AgencyType;
use App\Enums\Classification;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\RecordAccessLog;
use App\Models\SecurityAgency;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(fn () => CrossFixtures::system());

function formationPayload(array $overrides = []): array
{
    return [
        'name' => 'Ikeja Division',
        'formation_type' => 'division',
        'is_temporary' => false,
        'status' => 'active',
        'classification' => 'restricted',
        ...$overrides,
    ];
}

test('formations follow the jurisdiction matrix', function (string $who) {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        AgencyFormation::factory()->of($agency)->in($world->lgas[$lga])->create(['name' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    expect(app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => AgencyFormation::query()->orderBy('name')->pluck('name')->all()))
        ->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('formations are classified: levels above the viewer are invisible and cannot be assigned', function () {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create();

    foreach (Classification::cases() as $level) {
        AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->classified($level)->create(['name' => $level->value]);
    }

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => AgencyFormation::query()->orderBy('name')->pluck('name')->all());

    expect($visible('lga_chief'))->toBe(['restricted'])
        ->and($visible('deputy_state_director'))->toBe(['confidential', 'restricted'])
        ->and($visible('state_director'))->toBe(['confidential', 'restricted', 'secret'])
        ->and($visible('super_admin'))->toBe([]);

    CrossFixtures::signIn($world->users['lga_chief'])->post('/formations', formationPayload([
        'lga' => $world->lgas['A1a']->ulid, 'agency' => $agency->ulid, 'classification' => 'confidential',
    ]))->assertSessionHasErrors(['classification' => 'You cannot classify a record above your own clearance.']);
});

test('an LGA chief records a formation in their LGA, with location and community; views are logged', function () {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create(['name' => 'Nigeria Police Force']);
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];

    CrossFixtures::signIn($chief)->post('/formations', formationPayload([
        'lga' => $world->lgas['A1a']->ulid,
        'agency' => $agency->ulid,
        'community' => $community->ulid,
        'latitude' => 6.6,
        'longitude' => 3.35,
        'commander_title' => 'Commissioner',
        'personnel_strength' => 120,
    ]))->assertRedirect();

    $formation = AgencyFormation::query()->withCoordinates()->sole();
    expect($formation->lga_id)->toBe($world->lgas['A1a']->id)
        ->and($formation->community_id)->toBe($community->id)
        ->and(round((float) $formation->latitude, 2))->toBe(6.6)
        ->and($formation->created_by)->toBe($chief->id);

    // Another LGA is refused.
    CrossFixtures::signIn($chief)->post('/formations', formationPayload(['lga' => $world->lgas['A1b']->ulid, 'agency' => $agency->ulid]))
        ->assertSessionHasErrors('lga');

    CrossFixtures::signIn($chief)->get("/formations/{$formation->ulid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('formations/show')->where('formation.name', 'Ikeja Division')->missing('formation.id'));
    expect(RecordAccessLog::query()->where('subject_id', $formation->id)->where('user_id', $chief->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'location_changed')->where('subject_id', $formation->id)->exists())->toBeTrue();

    // Out of jurisdiction: 404.
    CrossFixtures::signIn($world->users['ddg_zone_b'])->get("/formations/{$formation->ulid}")->assertNotFound();
});

test('a state agency\'s formations are only in its state', function () {
    $world = JurisdictionWorld::build();
    $stateAgency = SecurityAgency::factory()->create(['agency_type' => AgencyType::State, 'state_id' => $world->states['B1']->id]);

    CrossFixtures::signIn($world->users['state_director'])->post('/formations', formationPayload(['lga' => $world->lgas['A1a']->ulid, 'agency' => $stateAgency->ulid]))
        ->assertSessionHasErrors('agency');

    expect(fn () => DB::transaction(fn () => AgencyFormation::factory()->of($stateAgency)->in($world->lgas['A1a'])->create()))
        ->toThrow(QueryException::class, 'a state agency');

    expect(AgencyFormation::factory()->of($stateAgency)->in($world->lgas['B1'])->create()->exists)->toBeTrue();
});

test('a parent belongs to the same agency, and the hierarchy has no cycles', function () {
    $world = JurisdictionWorld::build();
    $police = SecurityAgency::factory()->create();
    $army = SecurityAgency::factory()->create();
    $command = AgencyFormation::factory()->of($police)->in($world->lgas['A1a'])->create(['formation_type' => FormationType::Command]);
    $division = AgencyFormation::factory()->of($police)->in($world->lgas['A1a'])->create(['formation_type' => FormationType::Division, 'parent_formation_id' => $command->id]);
    $station = AgencyFormation::factory()->of($police)->in($world->lgas['A1a'])->create(['parent_formation_id' => $division->id]);
    $barracks = AgencyFormation::factory()->of($army)->in($world->lgas['A1a'])->create();
    $sd = $world->users['state_director'];

    // Another agency's parent.
    CrossFixtures::signIn($sd)->put("/formations/{$station->ulid}", formationPayload(['name' => $station->name, 'parent' => $barracks->ulid]))
        ->assertSessionHasErrors(['parent' => 'The parent formation must belong to the same agency.']);

    // A descendant as parent.
    CrossFixtures::signIn($sd)->put("/formations/{$command->ulid}", formationPayload(['name' => $command->name, 'formation_type' => 'command', 'parent' => $station->ulid]))
        ->assertSessionHasErrors(['parent' => 'A formation cannot sit under itself or one of its own sub-formations.']);

    // The database refuses both too.
    expect(fn () => DB::transaction(fn () => $command->forceFill(['parent_formation_id' => $station->id])->save()))->toThrow(QueryException::class, 'cycle')
        ->and(fn () => DB::transaction(fn () => $barracks->forceFill(['parent_formation_id' => $command->id])->save()))->toThrow(QueryException::class, 'same agency');

    // A valid move.
    CrossFixtures::signIn($sd)->put("/formations/{$station->ulid}", formationPayload(['name' => $station->name, 'formation_type' => 'station', 'parent' => $command->ulid]))
        ->assertSessionHasNoErrors();
    expect($station->fresh()->parent_formation_id)->toBe($command->id);

    // Absent parent: unchanged.
    CrossFixtures::signIn($sd)->put("/formations/{$station->ulid}", formationPayload(['name' => 'Renamed', 'formation_type' => 'station']))
        ->assertSessionHasNoErrors();
    expect($station->fresh()->parent_formation_id)->toBe($command->id);
});

test('a hidden parent is shown as hidden, never named', function () {
    $world = JurisdictionWorld::build();
    $police = SecurityAgency::factory()->create();
    $command = AgencyFormation::factory()->of($police)->in($world->lgas['A1b'])->create(['name' => 'Secret Command']);
    $station = AgencyFormation::factory()->of($police)->in($world->lgas['A1a'])->create(['parent_formation_id' => $command->id]);

    CrossFixtures::signIn($world->users['lga_chief'])->get("/formations/{$station->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('formation.parent', null)->where('formation.parent_hidden', true))
        ->assertDontSee('Secret Command');
});

test('the location must fall in the LGA when it has a boundary', function () {
    $world = JurisdictionWorld::build();
    DB::statement("UPDATE lgas SET boundary = ST_GeogFromText('SRID=4326;MULTIPOLYGON(((3 6, 3.5 6, 3.5 6.5, 3 6.5, 3 6)))') WHERE id = ?", [$world->lgas['A1a']->id]);
    $agency = SecurityAgency::factory()->create();

    CrossFixtures::signIn($world->users['lga_chief'])->post('/formations', formationPayload([
        'lga' => $world->lgas['A1a']->ulid, 'agency' => $agency->ulid, 'latitude' => 9.0, 'longitude' => 7.4,
    ]))->assertSessionHasErrors('longitude');

    $formation = AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create();
    expect(fn () => DB::transaction(fn () => $formation->forceFill(['location' => AgencyFormation::pointFrom(9.0, 7.4)])->save()))
        ->toThrow(QueryException::class, 'outside its LGA boundary');
});

test('temporary formations need a start date and are ended by the daily job', function () {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create();

    CrossFixtures::signIn($world->users['lga_chief'])->post('/formations', formationPayload([
        'lga' => $world->lgas['A1a']->ulid, 'agency' => $agency->ulid, 'formation_type' => 'checkpoint', 'is_temporary' => true,
    ]))->assertSessionHasErrors('active_from');

    $past = AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create(['is_temporary' => true, 'active_from' => now()->subDays(10), 'active_to' => now()->subDay()]);
    $current = AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create(['is_temporary' => true, 'active_from' => now()->subDays(10), 'active_to' => now()]);
    $permanent = AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create();

    $this->artisan('buffalo:expire-temporary-formations')->assertSuccessful();

    expect($past->fresh()->status)->toBe(FormationStatus::Inactive)
        ->and($current->fresh()->status)->toBe(FormationStatus::Active)
        ->and($permanent->fresh()->status)->toBe(FormationStatus::Active)
        ->and(Activity::query()->where('event', 'formation_expired')->where('subject_id', $past->id)->exists())->toBeTrue();
});

test('only DG, DDG and State Directors delete formations; agencies in use are protected', function () {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create();
    $formation = AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create();

    expect(app(ReferenceUsage::class)->isInUse($agency))->toBeTrue();

    foreach (['lga_chief', 'deputy_state_director', 'super_admin'] as $who) {
        expect($world->users[$who]->can('delete', $formation))->toBeFalse();
    }

    CrossFixtures::signIn($world->users['state_director'])->delete("/formations/{$formation->ulid}", ['reason' => 'Duplicate entry'])->assertRedirect('/formations');
    expect($formation->fresh()->trashed())->toBeTrue();
});

test('the formations list, map and LGA Security presence tab show only visible formations', function () {
    $world = JurisdictionWorld::build();
    $agency = SecurityAgency::factory()->create();
    AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->create(['name' => 'Mine', 'location' => AgencyFormation::pointFrom(6.5, 3.3)]);
    AgencyFormation::factory()->of($agency)->in($world->lgas['A1a'])->classified(Classification::Secret)->create(['name' => 'Too secret']);
    AgencyFormation::factory()->of($agency)->in($world->lgas['B1'])->create(['name' => 'Elsewhere']);
    $chief = $world->users['lga_chief'];

    CrossFixtures::signIn($chief)->get('/formations')
        ->assertInertia(fn (Assert $page) => $page->has('formations.data', 1)->where('formations.data.0.name', 'Mine')->has('markers', 1));

    CrossFixtures::signIn($chief)->get("/lgas/{$world->lgas['A1a']->ulid}?tab=security_presence")
        ->assertInertia(fn (Assert $page) => $page->has('securityPresence.formations', 1)->where('securityPresence.formations.0.name', 'Mine'));

    CrossFixtures::signIn($chief)->get('/formations?q=Min')
        ->assertInertia(fn (Assert $page) => $page->has('formations.data', 1));
});
