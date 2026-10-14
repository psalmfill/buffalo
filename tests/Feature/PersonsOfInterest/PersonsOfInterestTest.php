<?php

use App\Enums\Classification;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
use App\Models\RecordAccessLog;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\PoiReviewsOverdue;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

beforeEach(fn () => PersonFixtures::system());

function designation(array $overrides = []): array
{
    return [
        'offence_categories' => [],
        'known_offences' => 'Suspected of armed robbery on the expressway.',
        'modus_operandi' => null,
        'latitude' => null,
        'longitude' => null,
        'last_known_location_description' => null,
        'last_sighted_at' => null,
        'risk_level' => 'high',
        'status' => 'at_large',
        'comment' => 'Named by two witnesses.',
        ...$overrides,
    ];
}

function poiFor(Person $person, array $attributes = []): PersonOfInterest
{
    $poi = PersonOfInterest::factory()->forPerson($person)->create($attributes);
    $person->roles()->forceCreate(['person_role_type_id' => PersonRoleType::idFor(PersonRoleType::PERSON_OF_INTEREST), 'is_active' => true]);

    return $poi;
}

/*
|--------------------------------------------------------------------------
| Visibility and access logging
|--------------------------------------------------------------------------
*/

test('POIs follow the jurisdiction matrix', function (string $who) {
    $world = JurisdictionWorld::build();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        PersonOfInterest::factory()->forPerson(Person::factory()->in($world->lgas[$lga])->create())->create(['known_offences' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    expect(app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => PersonOfInterest::query()->orderBy('known_offences')->pluck('known_offences')->all()))
        ->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('a POI is visible only when its person is: classification comes from the person', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $confidential = poiFor(Person::factory()->in($lga)->classified(Classification::Confidential)->create());
    $secret = poiFor(Person::factory()->in($lga)->classified(Classification::Secret)->create());
    $restricted = poiFor(Person::factory()->in($lga)->create());

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => PersonOfInterest::query()->pluck('id')->sort()->values()->all());

    expect($visible('lga_chief'))->toBe([$restricted->id])
        ->and($visible('deputy_state_director'))->toBe(collect([$confidential->id, $restricted->id])->sort()->values()->all())
        ->and($visible('state_director'))->toHaveCount(3);

    // No classification column of its own.
    expect(Schema::hasColumn('persons_of_interest', 'classification'))->toBeFalse();

    // Reclassifying the person hides the POI.
    $restricted->person->forceFill(['classification' => Classification::Secret])->save();
    expect($visible('lga_chief'))->toBe([]);

    $this->actingAs($world->users['deputy_state_director'])->get("/persons-of-interest/{$secret->ulid}/edit")->assertNotFound();
});

test('viewing POI details is access-logged with context "poi"', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $poi = poiFor($person);

    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}?tab=poi")
        ->assertInertia(fn (Assert $page) => $page->where('poi.ulid', $poi->ulid));

    PersonFixtures::system();
    $poiLogs = RecordAccessLog::query()->where('subject_type', PersonOfInterest::class)->get();

    expect($poiLogs)->toHaveCount(1)
        ->and($poiLogs[0]->context)->toBe('poi')
        ->and($poiLogs[0]->subject_id)->toBe($poi->id)
        ->and($poiLogs[0]->user_id)->toBe($world->users['lga_chief']->id);

    // Other tabs do not read the POI.
    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}?tab=profile");
    PersonFixtures::system();
    expect(RecordAccessLog::query()->where('subject_type', PersonOfInterest::class)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Designation, status and risk
|--------------------------------------------------------------------------
*/

test('designating creates the POI where the person is, due for review in 12 months, with the role', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    ThreatType::query()->create(['name' => 'Armed robbery', 'code' => 'armed_robbery_test', 'category' => 'crime']);

    $this->actingAs($world->users['lga_chief'])
        ->post("/persons/{$person->ulid}/person-of-interest", designation(['offence_categories' => ['armed_robbery_test'], 'latitude' => 6.45, 'longitude' => 3.4]))
        ->assertSessionHasNoErrors();

    PersonFixtures::system();
    $poi = PersonOfInterest::query()->sole();

    expect($poi->lga_id)->toBe($person->lga_id)
        ->and($poi->state_id)->toBe($person->state_id)
        ->and($poi->risk_level)->toBe(RiskLevel::High)
        ->and($poi->review_due_at->toDateString())->toBe(CarbonImmutable::today()->addMonthsNoOverflow(12)->toDateString())
        ->and($person->holdsRole(PersonRoleType::PERSON_OF_INTEREST))->toBeTrue()
        ->and(Activity::query()->where('event', 'poi_designated')->sole()->properties['comment'])->toBe('Named by two witnesses.')
        // Threat types used by POIs are "in use" (ReferenceUsage).
        ->and(app(ReferenceUsage::class)->isInUse(ThreatType::query()->where('code', 'armed_robbery_test')->sole()))->toBeTrue()
        ->and(app(ReferenceUsage::class)->isInUse(ThreatType::query()->create(['name' => 'Unused', 'code' => 'unused_test', 'category' => 'crime'])))->toBeFalse();

    // Only once per person.
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/person-of-interest", designation())->assertStatus(409);
});

test('the database keeps a POI\'s place equal to its person\'s', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();

    expect(fn () => PersonOfInterest::factory()->forPerson($person)->create(['lga_id' => $world->lgas['A1b']->id]))
        ->toThrow(QueryException::class);
});

test('designation requires grounds and cannot start as cleared', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();

    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/person-of-interest", designation(['comment' => '']))->assertSessionHasErrors('comment');
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/person-of-interest", designation(['status' => 'cleared']))->assertSessionHasErrors('status');
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/person-of-interest", designation(['offence_categories' => ['not_a_code']]))->assertSessionHasErrors('offence_categories.0');
});

test('status and risk changes require a comment and are logged with old and new values', function () {
    $world = JurisdictionWorld::build();
    $poi = poiFor(Person::factory()->in($world->lgas['A1a'])->create());
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'detained'])->assertSessionHasErrors('comment');
    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/status", ['comment' => 'Nothing to change'])->assertSessionHasErrors(['status', 'risk_level']);
    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'detained', 'risk_level' => 'critical', 'comment' => 'Arrested at Mile 2.'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'on_trial', 'comment' => 'Arraigned today.'])->assertSessionHasNoErrors();

    PersonFixtures::system();
    $poi->refresh();
    $status = Activity::query()->where('event', 'poi_status_changed')->orderBy('id')->get();
    $risk = Activity::query()->where('event', 'poi_risk_changed')->sole();

    expect($poi->status)->toBe(PoiStatus::OnTrial)
        ->and($poi->risk_level)->toBe(RiskLevel::Critical)
        ->and($status->map(fn ($a) => $a->properties->only(['old', 'new', 'comment'])->all())->all())->toBe([
            ['old' => 'at_large', 'new' => 'detained', 'comment' => 'Arrested at Mile 2.'],
            ['old' => 'detained', 'new' => 'on_trial', 'comment' => 'Arraigned today.'],
        ])
        ->and($risk->properties->only(['old', 'new'])->all())->toBe(['old' => 'medium', 'new' => 'critical']);

    // The timeline on the POI tab shows them, newest first.
    $this->actingAs($chief)->get("/persons/{$poi->person->ulid}?tab=poi")->assertInertia(fn (Assert $page) => $page
        ->where('poi.timeline.0.comment', 'Arraigned today.')
        ->where('poi.timeline.0.event', 'poi_status_changed'));
});

test('clearing needs poi.clear, sets a review in 30 days and deactivates the role; reopening reactivates it', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $poi = poiFor($person);

    $this->actingAs($world->users['deputy_state_director'])->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'cleared', 'comment' => 'No evidence.'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['lga_chief'])->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'cleared', 'comment' => 'No evidence.'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'cleared', 'comment' => 'No evidence.'])->assertSessionHasNoErrors();

    PersonFixtures::system();
    $poi->refresh();
    $role = PersonRole::query()->where('person_id', $person->id)->where('person_role_type_id', PersonRoleType::idFor('person_of_interest'))->sole();

    expect($poi->status)->toBe(PoiStatus::Cleared)
        ->and($poi->review_due_at->toDateString())->toBe(CarbonImmutable::today()->addDays(30)->toDateString())
        ->and($role->is_active)->toBeFalse()
        ->and(PersonOfInterest::query()->whereKey($poi->id)->exists())->toBeTrue();

    $this->actingAs($world->users['state_director'])->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'under_surveillance', 'comment' => 'New intelligence.'])->assertSessionHasNoErrors();
    PersonFixtures::system();
    expect($role->fresh()?->is_active)->toBeTrue();
});

test('status deceased marks the person deceased', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create(['nin' => '41234567890']);
    $poi = poiFor($person);

    $this->actingAs($world->users['lga_chief'])->post("/persons-of-interest/{$poi->ulid}/status", ['status' => 'deceased', 'comment' => 'Confirmed by the hospital.'])->assertSessionHasNoErrors();

    PersonFixtures::system();
    $fresh = Person::query()->findOrFail($person->id);
    expect($fresh->is_deceased)->toBeTrue()
        ->and($fresh->nin)->toBe('41234567890');
});

test('mark reviewed requires a comment and sets the next review', function () {
    $world = JurisdictionWorld::build();
    $poi = poiFor(Person::factory()->in($world->lgas['A1a'])->create(), ['review_due_at' => now()->subDay()->toDateString()]);
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/review", ['comment' => ''])->assertSessionHasErrors('comment');
    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/review", ['comment' => 'Still at large.'])->assertSessionHasNoErrors();

    PersonFixtures::system();
    $poi->refresh();
    expect($poi->review_due_at->toDateString())->toBe(CarbonImmutable::today()->addMonthsNoOverflow(12)->toDateString())
        ->and($poi->last_reviewed_by)->toBe($chief->id)
        ->and(Activity::query()->where('event', 'poi_reviewed')->sole()->properties['comment'])->toBe('Still at large.');

    $this->actingAs($chief)->post("/persons-of-interest/{$poi->ulid}/review", ['comment' => 'Checked again.', 'next_review' => now()->addMonths(3)->toDateString()]);
    PersonFixtures::system();
    expect($poi->fresh()?->review_due_at->toDateString())->toBe(now()->addMonths(3)->toDateString());
});

/*
|--------------------------------------------------------------------------
| Review reminders
|--------------------------------------------------------------------------
*/

test('the review reminder notifies the owning LGA chief and State Director, once per due date', function () {
    Notification::fake();
    $world = JurisdictionWorld::build();
    $overdue = fn (Lga $lga) => poiFor(Person::factory()->in($lga)->create(), ['review_due_at' => now()->subDays(2)->toDateString()]);
    $overdue($world->lgas['A1a']);
    $overdue($world->lgas['A1a']);
    $overdue($world->lgas['A1b']);
    poiFor(Person::factory()->in($world->lgas['A1a'])->create(), ['review_due_at' => now()->addDay()->toDateString()]);
    $otherChief = User::factory()->lgaChief($world->lgas['A1b'])->create();

    $this->artisan('buffalo:poi-review-reminders')->assertSuccessful();

    Notification::assertSentTo($world->users['lga_chief'], PoiReviewsOverdue::class, fn (PoiReviewsOverdue $n) => $n->count === 2 && str_contains($n->body($world->users['lga_chief']), 'LGA'));
    Notification::assertSentTo($otherChief, PoiReviewsOverdue::class, fn (PoiReviewsOverdue $n) => $n->count === 1);
    Notification::assertSentTo($world->users['state_director'], PoiReviewsOverdue::class, fn (PoiReviewsOverdue $n) => $n->count === 3);
    Notification::assertNotSentTo($world->users['deputy_state_director'], PoiReviewsOverdue::class);
    Notification::assertNotSentTo($world->users['director_general'], PoiReviewsOverdue::class);
    Notification::assertNotSentTo($world->users['ddg_zone_b'], PoiReviewsOverdue::class);

    // Payloads carry counts, never names.
    Notification::assertSentTo($world->users['lga_chief'], PoiReviewsOverdue::class, fn (PoiReviewsOverdue $n) => ! str_contains($n->body($world->users['lga_chief']), 'Test Person'));

    // Not again for the same due dates.
    Notification::fake();
    $this->artisan('buffalo:poi-review-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    // Scheduled daily.
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'buffalo:poi-review-reminders'));
    expect($event?->expression)->toBe('0 7 * * *');
});

/*
|--------------------------------------------------------------------------
| List and deletion
|--------------------------------------------------------------------------
*/

test('the list is sorted by risk then last sighted, and filters', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $make = fn (string $name, RiskLevel $risk, ?string $sighted, array $extra = []) => poiFor(Person::factory()->in($lga)->named($name, 'Poi')->create(), ['risk_level' => $risk, 'last_sighted_at' => $sighted, ...$extra]);
    $make('Medium', RiskLevel::Medium, null);
    $make('CriticalOld', RiskLevel::Critical, now()->subMonth()->toDateTimeString());
    $make('CriticalNew', RiskLevel::Critical, now()->subDay()->toDateTimeString());
    $make('Low', RiskLevel::Low, now()->toDateTimeString(), ['review_due_at' => now()->subDay()->toDateString()]);

    $names = fn (array $query = []) => collect($this->actingAs($world->users['state_director'])->get('/persons-of-interest?'.http_build_query($query))->inertiaProps('results.data'))->pluck('person.name')->all();

    expect($names())->toBe(['CriticalNew Poi', 'CriticalOld Poi', 'Medium Poi', 'Low Poi'])
        ->and($names(['risk' => 'critical']))->toBe(['CriticalNew Poi', 'CriticalOld Poi'])
        ->and($names(['overdue' => '1']))->toBe(['Low Poi']);
});

test('deleting a POI needs State Director or above and a reason, and deactivates the role', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $poi = poiFor($person);

    $this->actingAs($world->users['deputy_state_director'])->delete("/persons-of-interest/{$poi->ulid}", ['reason' => 'Mistake'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete("/persons-of-interest/{$poi->ulid}", [])->assertSessionHasErrors('reason');
    $this->actingAs($world->users['state_director'])->delete("/persons-of-interest/{$poi->ulid}", ['reason' => 'Wrong person'])->assertRedirect();

    PersonFixtures::system();
    expect(PersonOfInterest::query()->count())->toBe(0)
        ->and(PersonOfInterest::withTrashed()->count())->toBe(1)
        ->and($person->holdsRole(PersonRoleType::PERSON_OF_INTEREST))->toBeFalse()
        ->and(Activity::query()->where('event', 'person_of_interest_deleted')->sole()->properties['reason'])->toBe('Wrong person');

    // A new designation is possible afterwards.
    $this->actingAs($world->users['state_director'])->post("/persons/{$person->ulid}/person-of-interest", designation())->assertSessionHasNoErrors();
});

test('POI permissions per role', function (string $who, bool $view, bool $manage, bool $clear) {
    $world = JurisdictionWorld::build();
    $poi = poiFor(Person::factory()->in($world->lgas['A1a'])->create());
    $user = $world->users[$who];

    expect($user->can('view', $poi))->toBe($view)
        ->and($user->can('update', $poi))->toBe($manage)
        ->and($user->can('clear', $poi))->toBe($clear);
})->with([
    ['super_admin', false, false, false],
    ['director_general', true, true, true],
    ['state_director', true, true, true],
    ['deputy_state_director', true, true, false],
    ['lga_chief', true, true, false],
    ['ddg_zone_b', false, false, false],
]);
