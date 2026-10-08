<?php

use App\Enums\PersonRoleType;
use App\Enums\PoliticalOfficeType;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 10B.3: correcting an office recorded against the wrong person.
*/

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->wrong = Person::factory()->in($this->lga)->named('Bala', 'Wrongly')->withRole(PersonRoleType::PoliticalLeader)->create();
    $this->right = Person::factory()->in($this->world->lgas['A1b'])->named('Bala', 'Rightly')->create();
    $district = SenatorialDistrict::query()->findOrFail($this->lga->senatorial_district_id);
    $this->office = PoliticalOffice::factory()->forPerson($this->wrong)->senator($district)->create();
    $this->reason = 'Recorded against a namesake; the senator is the other Bala.';
});

function isLeader(Person $person): bool
{
    return (bool) PersonRole::query()->where('person_id', $person->id)->where('role', PersonRoleType::PoliticalLeader->value)->value('is_active');
}

test('who may reassign an office', function (string $who, int $status) {
    CrossFixtures::signIn($this->world->users[$who])
        ->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $this->right->ulid, 'reason' => $this->reason])
        ->assertStatus($status);
})->with([
    ['super_admin', 404],
    ['director_general', 302],
    ['ddg_zone_a', 302],
    ['ddg_zone_b', 404],
    ['state_director', 302],
    ['deputy_state_director', 403],
    ['lga_chief', 403],
]);

test('a reassignment moves the office, the roles, and shows on both histories', function () {
    CrossFixtures::signIn($this->world->users['state_director'])
        ->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $this->right->ulid, 'reason' => $this->reason])
        ->assertRedirect("/persons/{$this->right->ulid}?tab=political_offices")
        ->assertSessionHasNoErrors();

    expect(DB::table('political_offices')->where('id', $this->office->id)->value('person_id'))->toBe($this->right->id)
        ->and(isLeader($this->right))->toBeTrue()
        ->and(isLeader($this->wrong))->toBeFalse();

    foreach ([$this->wrong, $this->right] as $person) {
        $event = Activity::query()->where('subject_type', Person::class)->where('subject_id', $person->id)->where('event', 'political_office_reassigned')->sole();
        expect($event->properties->toArray())->toMatchArray(['old_holder' => $this->wrong->ulid, 'new_holder' => $this->right->ulid, 'reason' => $this->reason]);

        CrossFixtures::signIn($this->world->users['state_director'])->get("/persons/{$person->ulid}?tab=history")
            ->assertInertia(fn (Assert $page) => $page->where('history.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['event'] ?? null) === 'political_office_reassigned')));
    }
});

test('the old holder keeps the role while holding another current office', function () {
    PoliticalOffice::factory()->forPerson($this->wrong)->inLga(PoliticalOfficeType::LgaChairman, $this->lga)->create();

    CrossFixtures::signIn($this->world->users['state_director'])
        ->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $this->right->ulid, 'reason' => $this->reason])
        ->assertSessionHasNoErrors();

    expect(isLeader($this->wrong))->toBeTrue();
});

test('the new holder must be a person in the user\'s own jurisdiction, and the reason long enough', function () {
    $sd = $this->world->users['state_director'];
    $elsewhere = Person::factory()->in($this->world->lgas['B1'])->create();

    CrossFixtures::signIn($sd)->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $elsewhere->ulid, 'reason' => $this->reason])->assertNotFound();
    CrossFixtures::signIn($sd)->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $this->right->ulid, 'reason' => 'Too short'])->assertSessionHasErrors('reason');
    CrossFixtures::signIn($sd)->post("/political-offices/{$this->office->ulid}/reassign", ['person' => $this->wrong->ulid, 'reason' => $this->reason])->assertSessionHasErrors('person');

    expect(DB::table('political_offices')->where('id', $this->office->id)->value('person_id'))->toBe($this->wrong->id);
});

test('the person page offers Reassign holder to State Directors only', function () {
    CrossFixtures::signIn($this->world->users['state_director'])->get("/persons/{$this->wrong->ulid}?tab=political_offices")
        ->assertInertia(fn (Assert $page) => $page->where('politicalOffices.current.0.can.reassign', true));
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/persons/{$this->wrong->ulid}?tab=political_offices")
        ->assertInertia(fn (Assert $page) => $page->where('politicalOffices.current.0.can.reassign', false));
});
