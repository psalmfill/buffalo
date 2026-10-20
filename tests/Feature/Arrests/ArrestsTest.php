<?php

use App\Enums\ArrestStatus;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\MatchContext;
use App\Enums\PoiStatus;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestPersonOffence;
use App\Models\ArrestStatusHistory;
use App\Models\IncidentAgency;
use App\Models\IncidentPerson;
use App\Models\OffenceType;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\ArrestNotification;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11C: arrests. The world: zone A (states A1, A2), zone B (B1); A1
| has LGAs A1a and A1b.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->police = SecurityAgency::factory()->create(['name' => 'Nigeria Police Force', 'short_name' => 'NPF']);
    $this->army = SecurityAgency::factory()->create(['name' => 'Nigerian Army', 'short_name' => 'NA']);
    $this->division = AgencyFormation::factory()->of($this->police)->in($this->lga)->create(['name' => 'Ikeja Division']);
    $this->kidnapping = OffenceType::factory()->create(['name' => 'Kidnapping', 'code' => 'kidnapping_t']);
    $this->firearms = OffenceType::factory()->create(['name' => 'Unlawful possession of firearms', 'code' => 'firearms_t']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function arrestPayload(array $overrides = []): array
{
    return [
        'lga' => test()->lga->ulid,
        'community' => null,
        'latitude' => null,
        'longitude' => null,
        'location_description' => 'At the motor park',
        'arrested_at' => CarbonImmutable::now('Africa/Lagos')->subHours(2)->format('Y-m-d\TH:i'),
        'arrested_at_precision' => 'exact',
        'agency' => test()->police->ulid,
        'formation' => test()->division->ulid,
        'incident' => null,
        'status' => 'in_custody',
        'notes' => '<p>Arrested after a <strong>tip-off</strong>.</p>',
        'classification' => 'restricted',
        'persons' => [['unidentified_description' => 'Tall man in a red cap', 'offences' => [['offence' => test()->kidnapping->ulid]]]],
        ...$overrides,
    ];
}

function latestArrest(): Arrest
{
    CrossFixtures::system();

    return Arrest::query()->latest('id')->firstOrFail();
}

test('who may see the arrests list and record arrests', function (string $who, int $list, int $create) {
    CrossFixtures::signIn($this->world->users[$who])->get('/arrests?lens=off')->assertStatus($list);
    CrossFixtures::signIn($this->world->users[$who])->get('/arrests/create')->assertStatus($create);
})->with([
    ['super_admin', 403, 403],
    ['director_general', 200, 200],
    ['ddg_zone_a', 200, 200],
    ['state_director', 200, 200],
    ['deputy_state_director', 200, 200],
    ['lga_chief', 200, 200],
]);

test('an arrest is recorded with a gap-free reference, persons, offences and the first status history', function () {
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload())->assertSessionHasNoErrors()->assertRedirect();
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload())->assertSessionHasNoErrors();

    $arrest = latestArrest();
    $year = CarbonImmutable::now('Africa/Lagos')->year;
    $code = strtoupper((string) DB::table('states')->where('id', $this->lga->state_id)->value('code'));

    expect($arrest->reference_no)->toBe("ARR/{$code}/{$year}/00002")
        ->and(Arrest::query()->orderBy('id')->first()?->reference_no)->toBe("ARR/{$code}/{$year}/00001")
        ->and($arrest->state_id)->toBe($this->lga->state_id)
        ->and(DB::table('arrests')->where('id', $arrest->id)->value('notes_text'))->toBe('Arrested after a tip-off.');

    $row = ArrestPerson::query()->where('arrest_id', $arrest->id)->sole();
    expect($row->unidentified_description)->toBe('Tall man in a red cap')
        ->and($row->state_id)->toBe($arrest->state_id)->and($row->lga_id)->toBe($arrest->lga_id)
        ->and(ArrestPersonOffence::query()->where('arrest_person_id', $row->id)->pluck('offence_type_id')->all())->toBe([$this->kidnapping->id])
        ->and(ArrestStatusHistory::query()->where('arrest_id', $arrest->id)->sole()->comment)->toBe('Arrest recorded.');
});

test('validation: time, agency formation, persons and offences', function () {
    $other = AgencyFormation::factory()->of($this->army)->in($this->lga)->create();
    $future = CarbonImmutable::now('Africa/Lagos')->addDay()->format('Y-m-d\TH:i');

    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['arrested_at' => $future]))->assertSessionHasErrors('arrested_at');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['formation' => $other->ulid]))->assertSessionHasErrors('formation');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => []]))->assertSessionHasErrors('persons');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['unidentified_description' => 'Someone', 'offences' => []]]]))->assertSessionHasErrors('persons.0.offences');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['unidentified_description' => '', 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))->assertSessionHasErrors('persons.0.unidentified_description');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['lga' => $this->world->lgas['B1']->ulid]))->assertSessionHasErrors('lga');

    expect(Arrest::query()->count())->toBe(0);
});

test('the linked incident must be visible and not invalid', function () {
    $invalid = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Invalid);
    $elsewhere = W::at(W::draft($this->world->lgas['B1'], CrossFixtures::chiefOf($this->world->lgas['B1'])), IncidentStatus::Verified);

    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['incident' => $invalid->ulid]))->assertSessionHasErrors('incident');
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['incident' => $elsewhere->ulid]))->assertSessionHasErrors('incident');
    CrossFixtures::signIn($this->chief)->get("/arrests/create?incident={$invalid->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->chief)->get("/arrests/create?incident={$elsewhere->ulid}")->assertNotFound();
});

test('Arrest made pre-fills the form from the incident; the incident date is only a hint', function () {
    $incident = W::at(W::draft($this->lga, $this->chief, ['location_description' => 'Expressway toll gate']), IncidentStatus::Verified);
    CrossFixtures::system();
    IncidentAgency::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'security_agency_id' => $this->army->id, 'role' => 'first_responder']);
    IncidentAgency::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'security_agency_id' => $this->police->id, 'agency_formation_id' => $this->division->id, 'role' => 'lead']);
    $suspect = Person::factory()->in($this->lga)->named('Bello', 'Suspect')->create();
    IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'person_id' => $suspect->id, 'involvement' => 'suspect']);
    IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'unidentified_description' => 'Short man with a scar', 'involvement' => 'suspect']);
    IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'unidentified_description' => 'A witness', 'involvement' => 'witness']);

    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.recordArrest', true));

    CrossFixtures::signIn($this->sd)->get("/arrests/create?incident={$incident->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('arrests/form')
        ->where('prefill.incident.value', $incident->ulid)
        ->where('prefill.lga', $this->lga->ulid)
        ->where('prefill.location_description', 'Expressway toll gate')
        ->where('prefill.agencies.0.value', $this->army->ulid)
        ->where('prefill.agencies.1.formation', $this->division->ulid)
        ->where('prefill.suspects', fn ($s) => collect($s)->map(fn ($x) => $x['person']['label'] ?? $x['unidentified_description'])->all() === ['Bello Suspect', 'Short man with a scar'])
        ->where('prefill.occurred_at', fn ($at) => is_string($at))
        ->where('arrest', null));
});

test('the acceptance scenario: two suspects arrested on a kidnapping incident; counts compared, never changed', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified);
    CrossFixtures::system();
    $poiPerson = Person::factory()->in($this->lga)->named('Garba', 'Poi')->create();
    $poi = PersonOfInterest::factory()->forPerson($poiPerson)->create(['status' => PoiStatus::AtLarge]);
    $other = Person::factory()->in($this->lga)->named('Ade', 'Plain')->create();

    CrossFixtures::signIn($this->sd)->post('/arrests', arrestPayload([
        'incident' => $incident->ulid,
        'persons' => [
            ['person' => $poiPerson->ulid, 'offences' => [['offence' => $this->kidnapping->ulid], ['offence' => $this->firearms->ulid]]],
            ['person' => $other->ulid, 'offences' => [['offence' => $this->kidnapping->ulid]]],
        ],
    ]))->assertSessionHasNoErrors();
    $arrest = latestArrest();

    // The incident page shows the arrest and the difference (0 reported, 2 recorded).
    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}?tab=arrests")->assertInertia(fn (Assert $page) => $page
        ->where('arrests.data.0.reference_no', $arrest->reference_no)
        ->where('arrestComparison', ['reported' => 0, 'recorded' => 2, 'differs' => true]));
    CrossFixtures::signIn($this->sd)->get("/arrests/{$arrest->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('comparison.differs', true)
        // The POI suggestion (detained) and the designation suggestion; nothing applied.
        ->where('persons.0.suggestion.kind', 'poi_detained')
        ->where('persons.1.suggestion.kind', 'designate_poi'));

    CrossFixtures::system();
    expect(DB::table('incident_casualties')->where('incident_id', $incident->id)->count())->toBe(0)
        ->and($poi->fresh()?->status)->toBe(PoiStatus::AtLarge);

    // The form's suggestions endpoint says the same for picked persons.
    CrossFixtures::signIn($this->sd)->postJson('/arrests/persons/suggestions', ['persons' => [$poiPerson->ulid, $other->ulid]])
        ->assertJsonPath("suggestions.{$poiPerson->ulid}.kind", 'poi_detained')
        ->assertJsonPath("suggestions.{$other->ulid}.kind", 'designate_poi');
});

test('arrests and every child table are scoped on their own place and classification', function () {
    $mine = Arrest::factory()->in($this->lga)->withPerson(null)->create();
    $elsewhere = Arrest::factory()->in($this->world->lgas['B1'])->withPerson(null)->create();
    $secret = Arrest::factory()->in($this->lga)->classified(Classification::Secret)->withPerson(null)->create();
    foreach ([$mine, $elsewhere, $secret] as $a) {
        DB::table('arrest_status_histories')->insert(['arrest_id' => $a->id, 'state_id' => $a->state_id, 'lga_id' => $a->lga_id, 'to_status' => 'in_custody', 'comment' => 'x', 'changed_at' => now()]);
    }

    $see = fn (User $user, string $class) => app(JurisdictionContext::class)->actingAs($user, fn () => $class::query()->count());

    expect($see($this->chief, Arrest::class))->toBe(1)
        ->and($see($this->dsd, Arrest::class))->toBe(1)
        ->and($see($this->sd, Arrest::class))->toBe(2)
        ->and($see($this->world->users['director_general'], Arrest::class))->toBe(3)
        ->and($see($this->world->users['ddg_zone_b'], Arrest::class))->toBe(1)
        ->and($see($this->world->users['super_admin'], Arrest::class))->toBe(0);

    foreach ([ArrestPerson::class, ArrestPersonOffence::class, ArrestStatusHistory::class] as $class) {
        expect($see($this->chief, $class))->toBe(1, $class)
            ->and($see($this->world->users['ddg_zone_b'], $class))->toBe(1, $class)
            ->and($see($this->dsd, $class))->toBe(1, $class);
    }

    CrossFixtures::signIn($this->chief)->get("/arrests/{$elsewhere->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->dsd)->get("/arrests/{$secret->ulid}")->assertNotFound();
});

test('a person grant makes the person linkable but never shows another state\'s arrests', function () {
    $b1Person = CrossFixtures::person($this->world->lgas['B1']);
    $b1Arrest = Arrest::factory()->in($this->world->lgas['B1'])->withPerson($b1Person)->create();

    // Without a grant the chief cannot record the B1 person.
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['person' => $b1Person->ulid, 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))
        ->assertSessionHasErrors('persons.0.person');

    CrossFixtures::grant($this->chief, CrossFixtures::directorOfB1($this->world));

    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['person' => $b1Person->ulid, 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))
        ->assertSessionHasNoErrors();
    $arrest = latestArrest();

    // The grant shows the person, never the B1 arrest or its rows.
    $seen = app(JurisdictionContext::class)->actingAs($this->chief, fn () => [Arrest::query()->pluck('id')->all(), ArrestPerson::query()->where('arrest_id', $b1Arrest->id)->count()]);
    expect($seen)->toBe([[$arrest->id], 0]);

    // The name shows to the chief (grant) but not to the DSD (no grant).
    CrossFixtures::signIn($this->chief)->get("/arrests/{$arrest->ulid}")->assertInertia(fn (Assert $page) => $page->where('persons.0.person.name', $b1Person->name));
    CrossFixtures::signIn($this->dsd)->get("/arrests/{$arrest->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('persons.0.person', null)
        ->where('persons.0.person_hidden', true));
});

test('people entered on the arrest form run the match check with context arrest_entry', function () {
    CrossFixtures::person($this->world->lgas['B1']);

    CrossFixtures::signIn($this->chief)->postJson('/arrests/persons/check', ['nin' => CrossFixtures::NIN])
        ->assertOk()->assertJsonCount(1, 'hits');

    expect(DB::table('record_match_events')->where('context', MatchContext::ArrestEntry->value)->count())->toBe(1);

    // Creating the person anyway needs a justification.
    $new = ['first_name' => 'Garba', 'last_name' => 'Musa', 'other_names' => 'Danjuma', 'gender' => 'male', 'lga' => $this->lga->ulid, 'nin' => CrossFixtures::NIN, 'classification' => 'restricted'];
    CrossFixtures::signIn($this->chief)->postJson('/arrests/persons', $new)->assertJsonValidationErrors('hit_justification');
    CrossFixtures::signIn($this->chief)->postJson('/arrests/persons', [...$new, 'hit_justification' => 'Arrested here; the other record is an older case.'])->assertCreated();
    expect(Activity::query()->where('event', 'created_despite_hit')->sole()->properties['context'])->toBe('arrest_entry');
});

test('status changes need a comment, write histories and events, and histories are append-only', function () {
    $a = Person::factory()->in($this->lga)->create();
    $b = Person::factory()->in($this->lga)->create();
    $arrest = Arrest::factory()->in($this->lga)->withPerson($a)->withPerson($b)->create();
    $rows = ArrestPerson::query()->where('arrest_id', $arrest->id)->orderBy('id')->get();

    CrossFixtures::signIn($this->sd)->post("/arrests/{$arrest->ulid}/status", ['status' => 'charged'])->assertSessionHasErrors('comment');

    // One person charged: a tie, so the arrest keeps its status.
    CrossFixtures::signIn($this->sd)->post("/arrests/{$arrest->ulid}/status", ['person' => $rows[0]->ulid, 'status' => 'charged', 'comment' => 'Charged at the magistrate court.'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect($rows[0]->fresh()?->status)->toBe(ArrestStatus::Charged)
        ->and($arrest->fresh()?->status)->toBe(ArrestStatus::InCustody);

    // The second too: the arrest now shows "charged".
    CrossFixtures::signIn($this->sd)->post("/arrests/{$arrest->ulid}/status", ['person' => $rows[1]->ulid, 'status' => 'charged', 'comment' => 'Charged as well.'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect($arrest->fresh()?->status)->toBe(ArrestStatus::Charged);

    // Arrest-wide: every person.
    CrossFixtures::signIn($this->sd)->post("/arrests/{$arrest->ulid}/status", ['status' => 'released_on_bail', 'comment' => 'Granted bail by the court.'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect(ArrestPerson::query()->where('arrest_id', $arrest->id)->pluck('status')->map->value->unique()->all())->toBe(['released_on_bail'])
        ->and(Activity::query()->where('event', 'status_changed')->count())->toBe(3)
        ->and(ArrestStatusHistory::query()->where('arrest_id', $arrest->id)->count())->toBeGreaterThanOrEqual(5);

    $history = ArrestStatusHistory::query()->where('arrest_id', $arrest->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('arrest_status_histories')->where('id', $history?->id)->update(['comment' => 'rewritten'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('arrest_status_histories')->where('id', $history?->id)->delete()))->toThrow(QueryException::class);

    CrossFixtures::signIn($this->sd)->get("/arrests/{$arrest->ulid}?tab=timeline")->assertInertia(fn (Assert $page) => $page
        ->where('timeline', fn ($t) => collect($t)->pluck('comment')->contains('Granted bail by the court.')));
});

test('editing keeps statuses, syncs persons and offences, and children follow the place', function () {
    $person = Person::factory()->in($this->lga)->create();
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['person' => $person->ulid, 'status' => 'charged', 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))->assertSessionHasNoErrors();
    $arrest = latestArrest();
    $row = ArrestPerson::query()->where('arrest_id', $arrest->id)->sole();

    $payload = arrestPayload([
        'lga' => $this->world->lgas['A1b']->ulid,
        'formation' => null,
        'persons' => [
            ['ulid' => $row->ulid, 'person' => $person->ulid, 'offences' => [['offence' => $this->firearms->ulid]]],
            ['unidentified_description' => 'Driver', 'status' => 'in_custody', 'offences' => [['offence' => $this->kidnapping->ulid]]],
        ],
    ]);
    unset($payload['status']);

    CrossFixtures::signIn($this->sd)->put("/arrests/{$arrest->ulid}", $payload)->assertSessionHasNoErrors();
    CrossFixtures::system();

    $rows = ArrestPerson::query()->where('arrest_id', $arrest->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe(ArrestStatus::Charged)
        ->and($rows[0]->lga_id)->toBe($this->world->lgas['A1b']->id)
        ->and(ArrestPersonOffence::query()->where('arrest_person_id', $rows[0]->id)->pluck('offence_type_id')->all())->toBe([$this->firearms->id])
        ->and(DB::table('arrest_person_offences')->where('arrest_person_id', $rows[0]->id)->value('lga_id'))->toBe($this->world->lgas['A1b']->id);

    // Statuses change only through the status action.
    CrossFixtures::signIn($this->sd)->put("/arrests/{$arrest->ulid}", [...$payload, 'status' => 'convicted'])->assertSessionHasErrors('status');
});

test('a new arrest of a POI notifies the POI state\'s SD and DSD who can see it; payloads carry no names or offences', function () {
    $poiPerson = Person::factory()->in($this->lga)->named('Garba', 'Secretive')->create();
    PersonOfInterest::factory()->forPerson($poiPerson)->create();

    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['persons' => [['person' => $poiPerson->ulid, 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))->assertSessionHasNoErrors();
    $arrest = latestArrest();

    Notification::assertSentTo([$this->sd, $this->dsd], ArrestNotification::class, function (ArrestNotification $n) use ($arrest) {
        $payload = json_encode($n->toArray($this->sd));

        return $n->event === 'poi_arrested' && $n->arrestUlid === $arrest->ulid
            && ! str_contains((string) $payload, 'Secretive') && ! str_contains((string) $payload, 'Kidnapping');
    });
    Notification::assertNotSentTo([$this->chief, $this->world->users['director_general']], ArrestNotification::class);

    // A secret arrest: the DSD cannot see it, so is not told.
    Notification::fake();
    CrossFixtures::signIn($this->sd)->post('/arrests', arrestPayload(['classification' => 'secret', 'persons' => [['person' => $poiPerson->ulid, 'offences' => [['offence' => $this->kidnapping->ulid]]]]]))->assertSessionHasNoErrors();
    Notification::assertNotSentTo($this->dsd, ArrestNotification::class);
});

test('an arrest on an escalated incident notifies the DG and the covering DDGs', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified, ['escalated_at' => now()]);

    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['incident' => $incident->ulid]))->assertSessionHasNoErrors();

    Notification::assertSentTo([$this->world->users['director_general'], $this->world->users['ddg_zone_a']], ArrestNotification::class, fn (ArrestNotification $n) => $n->event === 'escalated_incident');
    Notification::assertNotSentTo($this->world->users['ddg_zone_b'], ArrestNotification::class);
});

test('consolidating a duplicate incident moves its arrests to the original', function () {
    $original = W::at(W::draft($this->lga, $this->chief, ['title' => 'Original']), IncidentStatus::Verified);
    $duplicate = W::at(W::draft($this->lga, $this->chief, ['title' => 'Duplicate']), IncidentStatus::Submitted);
    $arrest = Arrest::factory()->in($this->lga)->withPerson(null)->create(['incident_id' => $duplicate->id]);

    W::step($this->sd, $duplicate, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $original->ulid, 'consolidate' => true, 'amendment_reason' => 'Consolidating the duplicate report.'])->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect($arrest->fresh()?->incident_id)->toBe($original->id)
        ->and(Activity::query()->where('subject_type', Arrest::class)->where('event', 'incident_consolidated')->exists())->toBeTrue();
});

test('global search finds an arrest by its reference and by words in its notes, within scope', function () {
    CrossFixtures::signIn($this->chief)->post('/arrests', arrestPayload(['notes' => '<p>Seized a <strong>pump-action</strong> rifle.</p>']))->assertSessionHasNoErrors();
    $arrest = latestArrest();
    $hits = fn (User $user, string $q) => collect(CrossFixtures::signIn($user)->getJson('/search/quick?q='.urlencode($q))->json('sections'))->firstWhere('key', 'arrests')['hits'] ?? [];

    expect(collect($hits($this->chief, $arrest->reference_no))->pluck('ulid')->all())->toBe([$arrest->ulid])
        ->and(collect($hits($this->chief, 'rifle'))->pluck('ulid')->all())->toBe([$arrest->ulid])
        ->and($hits($this->chief, 'strong'))->toBe([])
        ->and($hits($this->world->users['ddg_zone_b'], $arrest->reference_no))->toBe([]);
});

test('dashboards and analytics count arrests through scopes, including those on invalid incidents', function () {
    $invalid = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Invalid);
    Arrest::factory()->in($this->lga)->withPerson(null, [$this->kidnapping])->create(['incident_id' => $invalid->id, 'security_agency_id' => $this->police->id]);
    Arrest::factory()->in($this->world->lgas['A1b'])->withPerson(null, [$this->firearms])->create(['security_agency_id' => $this->army->id]);
    Arrest::factory()->in($this->world->lgas['B1'])->withPerson(null, [$this->firearms])->create();
    Arrest::factory()->in($this->lga)->withPerson(null)->create(['arrested_at' => now()->subDays(45)]);

    CrossFixtures::signIn($this->chief)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('arrests.count', 1));
    CrossFixtures::signIn($this->sd)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('arrests.count', 2)
        ->where('arrests.byAgency', fn ($r) => collect($r)->pluck('label')->sort()->values()->all() === ['Nigeria Police Force', 'Nigerian Army'])
        ->where('arrests.byOffence', fn ($r) => collect($r)->pluck('count', 'label')->all() === ['Kidnapping' => 1, 'Unlawful possession of firearms' => 1]));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard?lens=off')->assertInertia(fn (Assert $page) => $page
        ->where('stateGrid', fn ($cards) => collect($cards)->pluck('arrests30', 'state.ulid')->all() === [
            ...collect($cards)->pluck('arrests30', 'state.ulid')->all(),
            $this->world->states['A1']->ulid => 2,
            $this->world->states['B1']->ulid => 1,
        ]));

    $charts = collect(CrossFixtures::signIn($this->sd)->get('/analytics?tab=incidents&period=30d')->viewData('page')['props']['charts'] ?? [])->keyBy('key');
    expect(collect($charts['arrests_by_agency']['rows'])->sum('count'))->toBe(2)
        ->and($charts->has('arrests_trend'))->toBeTrue()
        ->and($charts->has('arrest_share_by_type'))->toBeTrue();
});

test('offence types are reference data with a threat category', function () {
    $admin = $this->world->users['super_admin'];
    $threat = ThreatType::factory()->create(['name' => 'Oil theft']);

    CrossFixtures::signIn($admin)->post('/reference-data/offence-types', ['name' => 'Illegal refining', 'code' => 'illegal_refining', 'description' => null, 'sort_order' => 5, 'threat_type' => $threat->ulid])
        ->assertSessionHasNoErrors();
    expect(OffenceType::query()->where('code', 'illegal_refining')->sole()->threat_type_id)->toBe($threat->id);

    // In use: by an arrest's offences.
    Arrest::factory()->in($this->lga)->withPerson(null, [$this->kidnapping])->create();
    CrossFixtures::signIn($admin)->get('/reference-data/offence-types')->assertInertia(fn (Assert $page) => $page
        ->where('values', fn ($rows) => collect($rows)->firstWhere('code', 'kidnapping_t')['in_use'] === true));
});

test('Arrests tabs appear on person, LGA and formation pages when there are arrests, never under a grant', function () {
    $person = CrossFixtures::person($this->world->lgas['B1']);
    $b1Director = CrossFixtures::directorOfB1($this->world);
    Arrest::factory()->in($this->world->lgas['B1'])->withPerson($person)->create();
    Arrest::factory()->in($this->lga)->withPerson(null)->create(['agency_formation_id' => $this->division->id, 'security_agency_id' => $this->police->id]);

    CrossFixtures::signIn($b1Director)->get("/persons/{$person->ulid}?tab=arrests")->assertInertia(fn (Assert $page) => $page
        ->where('tabs', fn ($tabs) => in_array('arrests', is_array($tabs) ? $tabs : $tabs->all(), true))
        ->where('arrests.total', 1));
    CrossFixtures::signIn($this->chief)->get("/lgas/{$this->lga->ulid}?tab=arrests")->assertInertia(fn (Assert $page) => $page->where('arrests.total', 1));
    CrossFixtures::signIn($this->chief)->get("/formations/{$this->division->ulid}?tab=arrests")->assertInertia(fn (Assert $page) => $page
        ->where('hasArrests', true)->where('arrests.total', 1));

    // Under a grant the person is visible, the arrests tab is not.
    CrossFixtures::grant($this->chief, $b1Director);
    CrossFixtures::signIn($this->chief)->get("/persons/{$person->ulid}?tab=arrests")->assertInertia(fn (Assert $page) => $page
        ->where('tabs', fn ($tabs) => ! in_array('arrests', is_array($tabs) ? $tabs : $tabs->all(), true)));
});
