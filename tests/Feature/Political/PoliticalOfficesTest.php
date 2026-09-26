<?php

use App\Enums\Classification;
use App\Enums\PersonRoleType;
use App\Enums\PoliticalOfficeType;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use App\Models\Ward;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionGate;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

function officePayload(Lga $lga, array $overrides = []): array
{
    return [
        'office' => 'lga_chairman',
        'office_title' => null,
        'party' => 'Test Party',
        'state' => $lga->state->ulid,
        'senatorial_district' => null,
        'lga' => $lga->ulid,
        'ward' => null,
        'constituency_name' => null,
        'started_on' => '2024-06-01',
        'ended_on' => null,
        ...$overrides,
    ];
}

beforeEach(fn () => PersonFixtures::system());

/*
|--------------------------------------------------------------------------
| Validation per office type
|--------------------------------------------------------------------------
*/

test('office validation per type: district, LGA and ward, all within the state', function (Closure $overrides, ?string $error) {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->create();
    $ward = Ward::factory()->create(['lga_id' => $lga->id]);
    $otherLga = $world->lgas['A2'];
    $otherWard = Ward::factory()->create(['lga_id' => $world->lgas['A1b']->id]);
    $otherDistrict = SenatorialDistrict::query()->findOrFail($otherLga->senatorial_district_id);

    $response = $this->actingAs($world->users['director_general'])
        ->post("/persons/{$person->ulid}/political-offices", officePayload($lga, $overrides(compact('lga', 'ward', 'otherLga', 'otherWard', 'otherDistrict'))));

    $error === null ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors($error);
})->with([
    'chairman with LGA' => [fn ($c) => [], null],
    'chairman without LGA' => [fn ($c) => ['lga' => null], 'lga'],
    'chairman with an LGA of another state' => [fn ($c) => ['lga' => $c['otherLga']->ulid], 'lga'],
    'senator with district' => [fn ($c) => ['office' => 'senator', 'lga' => null, 'senatorial_district' => $c['lga']->senatorialDistrict->ulid], null],
    'senator without district' => [fn ($c) => ['office' => 'senator', 'lga' => null], 'senatorial_district'],
    'senator with a district of another state' => [fn ($c) => ['office' => 'senator', 'lga' => null, 'senatorial_district' => $c['otherDistrict']->ulid], 'senatorial_district'],
    'councillor with ward' => [fn ($c) => ['office' => 'councillor', 'ward' => $c['ward']->ulid], null],
    'councillor without ward' => [fn ($c) => ['office' => 'councillor'], 'ward'],
    'councillor with a ward of another LGA' => [fn ($c) => ['office' => 'councillor', 'ward' => $c['otherWard']->ulid], 'ward'],
    'LGA outside the given district' => [fn ($c) => ['office' => 'house_of_reps_member', 'senatorial_district' => $c['otherDistrict']->ulid], 'senatorial_district'],
    'governor, state-wide' => [fn ($c) => ['office' => 'governor', 'lga' => null], null],
    'other needs a title' => [fn ($c) => ['office' => 'other', 'lga' => null], 'office_title'],
    'end before start' => [fn ($c) => ['ended_on' => '2020-01-01'], 'ended_on'],
]);

test('the database enforces the office type rules too', function (array $attributes) {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();

    expect(fn () => PoliticalOffice::factory()->forPerson($person)->create($attributes))
        ->toThrow(QueryException::class);
})->with([
    'senator without district' => [['office' => 'senator']],
    'chairman without LGA' => [['office' => 'lga_chairman']],
]);

test('recording an office gives the person the political leader role and is audited', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->create();

    $this->actingAs($world->users['lga_chief'])
        ->post("/persons/{$person->ulid}/political-offices", officePayload($lga))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast.type', 'success');

    PersonFixtures::system();
    expect(PersonRole::query()->where('person_id', $person->id)->sole()->only(['role', 'is_active']))->toBe(['role' => PersonRoleType::PoliticalLeader, 'is_active' => true])
        ->and(Activity::query()->where('subject_type', PoliticalOffice::class)->where('event', 'created')->count())->toBe(1);
});

test('an LGA chief can only record offices they will see', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->create();
    $chief = $world->users['lga_chief'];

    // Own district's senator: allowed. Neighbouring LGA's chairman or a
    // governor (state-wide): outside what they can see.
    $this->actingAs($chief)->post("/persons/{$person->ulid}/political-offices", officePayload($lga, ['office' => 'senator', 'lga' => null, 'senatorial_district' => $lga->senatorialDistrict->ulid]))->assertSessionHasNoErrors();
    $this->actingAs($chief)->post("/persons/{$person->ulid}/political-offices", officePayload($world->lgas['A1b']))->assertSessionHasErrors('state');
    $this->actingAs($chief)->post("/persons/{$person->ulid}/political-offices", officePayload($lga, ['office' => 'governor', 'lga' => null]))->assertSessionHasErrors('state');
});

/*
|--------------------------------------------------------------------------
| Visibility: the senatorial district rule for LGA chiefs
|--------------------------------------------------------------------------
*/

test('LGA chiefs see offices of their LGA and of their district without an LGA; states see all of theirs', function () {
    $world = JurisdictionWorld::build();
    $a1a = $world->lgas['A1a'];
    $sibling = Lga::factory()->create(['senatorial_district_id' => $a1a->senatorial_district_id]);
    $district = SenatorialDistrict::query()->findOrFail($a1a->senatorial_district_id);
    $holder = fn (Lga $lga) => Person::factory()->in($lga)->create();

    $offices = [
        'own_chairman' => PoliticalOffice::factory()->forPerson($holder($a1a))->inLga(PoliticalOfficeType::LgaChairman, $a1a)->create(),
        'sibling_chairman' => PoliticalOffice::factory()->forPerson($holder($sibling))->inLga(PoliticalOfficeType::LgaChairman, $sibling)->create(),
        'own_senator' => PoliticalOffice::factory()->forPerson($holder($a1a))->senator($district)->create(),
        'other_district_senator' => PoliticalOffice::factory()->forPerson($holder($world->lgas['A1b']))->senator(SenatorialDistrict::query()->findOrFail($world->lgas['A1b']->senatorial_district_id))->create(),
        'governor' => PoliticalOffice::factory()->forPerson($holder($a1a))->stateWide(PoliticalOfficeType::Governor, $a1a->state)->create(),
        'other_state_governor' => PoliticalOffice::factory()->forPerson($holder($world->lgas['B1']))->stateWide(PoliticalOfficeType::Governor, $world->lgas['B1']->state)->create(),
    ];
    $labels = array_flip(array_map(fn ($o) => $o->id, $offices));

    $visible = fn (string $who) => collect(app(JurisdictionContext::class)->actingAs(
        $world->users[$who],
        fn () => PoliticalOffice::query()->pluck('id')->all(),
    ))->map(fn ($id) => $labels[$id])->sort()->values()->all();

    expect($visible('lga_chief'))->toBe(['own_chairman', 'own_senator'])
        ->and($visible('state_director'))->toBe(['governor', 'other_district_senator', 'own_chairman', 'own_senator', 'sibling_chairman'])
        ->and($visible('director_general'))->toHaveCount(6)
        ->and($visible('super_admin'))->toBe([]);

    // The gate applies the same rule to single records.
    $gate = app(JurisdictionGate::class);
    $chief = $world->users['lga_chief'];

    foreach ($offices as $label => $office) {
        expect($gate->canAccess($chief, $office))->toBe(in_array($label, ['own_chairman', 'own_senator'], true), $label);
    }
});

test('an office is hidden when its holder is classified above the viewer', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $secret = Person::factory()->in($lga)->classified(Classification::Secret)->create();
    $office = PoliticalOffice::factory()->forPerson($secret)->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();

    $count = fn (string $who) => app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => PoliticalOffice::query()->count());

    expect($count('state_director'))->toBe(1)
        ->and($count('deputy_state_director'))->toBe(0)
        ->and($count('lga_chief'))->toBe(0);

    $this->actingAs($world->users['deputy_state_director'])->get("/political-offices/{$office->ulid}/edit")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Overlaps, deletion
|--------------------------------------------------------------------------
*/

test('overlapping holders of a single seat produce a warning, not an error', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    PoliticalOffice::factory()->forPerson(Person::factory()->in($lga)->create())->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();
    // An ended term does not overlap.
    PoliticalOffice::factory()->forPerson(Person::factory()->in($lga)->create())->inLga(PoliticalOfficeType::LgaChairman, $lga)->ended()->create();
    $person = Person::factory()->in($lga)->create();

    $this->actingAs($world->users['state_director'])
        ->post("/persons/{$person->ulid}/political-offices", officePayload($lga))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast.type', 'warning')
        ->assertSessionHas('toast.message', fn (string $m) => str_contains($m, 'There is 1 other current lga chairman'));

    PersonFixtures::system();
    expect(PoliticalOffice::query()->where('office', 'lga_chairman')->count())->toBe(3);

    // A councillor of another ward, or a commissioner (not single-seat): no warning.
    $this->actingAs($world->users['state_director'])
        ->post("/persons/{$person->ulid}/political-offices", officePayload($lga, ['office' => 'commissioner', 'lga' => null]))
        ->assertSessionHas('toast.type', 'success');
    $this->actingAs($world->users['state_director'])
        ->post("/persons/{$person->ulid}/political-offices", officePayload($lga, ['office' => 'commissioner', 'lga' => null]))
        ->assertSessionHas('toast.type', 'success');
});

test('deleting an office needs State Director or above and a reason', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $office = PoliticalOffice::factory()->forPerson(Person::factory()->in($lga)->create())->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();

    $this->actingAs($world->users['deputy_state_director'])->delete("/political-offices/{$office->ulid}", ['reason' => 'Mistake'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete("/political-offices/{$office->ulid}", [])->assertSessionHasErrors('reason');
    $this->actingAs($world->users['state_director'])->delete("/political-offices/{$office->ulid}", ['reason' => 'Recorded twice'])->assertRedirect();

    PersonFixtures::system();
    expect(PoliticalOffice::query()->count())->toBe(0)
        ->and(PoliticalOffice::withTrashed()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'political_office_deleted')->sole()->properties['reason'])->toBe('Recorded twice');
});

test('the person page lists current and past offices', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->create();
    PoliticalOffice::factory()->forPerson($person)->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();
    PoliticalOffice::factory()->forPerson($person)->inLga(PoliticalOfficeType::LgaViceChairman, $lga)->ended()->create();

    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}?tab=political_offices")->assertInertia(fn (Assert $page) => $page
        ->has('politicalOffices.current', 1)
        ->where('politicalOffices.current.0.office.value', 'lga_chairman')
        ->has('politicalOffices.past', 1)
        ->where('politicalOffices.past.0.office.value', 'lga_vice_chairman'));
});
