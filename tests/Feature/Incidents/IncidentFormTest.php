<?php

use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Enums\IncidentStatus;
use App\Enums\MatchContext;
use App\Models\AgencyFormation;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PropertyType;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->lga = $this->world->lgas['A1a'];
    $this->type = IncidentType::factory()->create(['name' => 'Kidnapping', 'details_schema' => [
        ['key' => 'victims_count', 'label' => 'Number of victims', 'type' => 'integer', 'required' => true],
        ['key' => 'ransom_demanded', 'label' => 'Ransom demanded', 'type' => 'boolean'],
    ]]);
    $this->police = SecurityAgency::factory()->create(['name' => 'Nigeria Police Force']);
    $this->vehicle = PropertyType::factory()->create(['name' => 'Vehicle']);
});

function incidentPayload(array $overrides = []): array
{
    return [
        'incident_type' => test()->type->ulid,
        'details' => ['victims_count' => 2, 'ransom_demanded' => true],
        'title' => 'Travellers abducted on the expressway',
        'description' => 'Gunmen stopped a bus and abducted two passengers.',
        'severity' => 'high',
        'occurred_at' => now()->subHours(3)->format('Y-m-d\TH:i'),
        'occurred_at_precision' => 'approximate',
        'lga' => test()->lga->ulid,
        'community' => null,
        'latitude' => null,
        'longitude' => null,
        'location_description' => 'Near the toll gate',
        'action_taken' => null,
        'classification' => 'restricted',
        'casualties' => [],
        'persons' => [],
        'groups' => [],
        'agencies' => [],
        'properties' => [],
        ...$overrides,
    ];
}

function saveIncident(User $user, array $payload, ?Incident $incident = null): TestResponse
{
    $client = CrossFixtures::signIn($user);

    return $incident === null
        ? $client->post('/incidents', $payload)
        : $client->put("/incidents/{$incident->ulid}", $payload);
}

function onlyIncident(): Incident
{
    CrossFixtures::system();

    return Incident::query()->latest('id')->firstOrFail();
}

test('only intelligence roles report incidents, and only within their jurisdiction', function () {
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/incidents/create')->assertForbidden();
    CrossFixtures::signIn($this->chief)->get('/incidents/create')->assertOk();

    saveIncident($this->chief, incidentPayload(['lga' => $this->world->lgas['A1b']->ulid]))->assertSessionHasErrors('lga');
    saveIncident($this->world->users['super_admin'], incidentPayload())->assertForbidden();
    expect(Incident::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a draft is saved with its related rows, the reporter and the place', function () {
    $formation = AgencyFormation::factory()->of($this->police)->in($this->lga)->create();
    $group = CriminalGroup::factory()->in($this->lga)->create();

    saveIncident($this->chief, incidentPayload([
        'casualties' => [
            ['outcome' => 'abducted', 'group' => 'civilian', 'agency' => null, 'adult_male' => 1, 'adult_female' => 1, 'child_male' => 0, 'child_female' => 0, 'unknown' => 0],
            ['outcome' => 'injured', 'group' => 'security_personnel', 'agency' => $this->police->ulid, 'adult_male' => 1, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0, 'unknown' => 0],
        ],
        'persons' => [['person' => null, 'unidentified_description' => 'Driver of the bus', 'involvement' => 'witness']],
        'groups' => [['group' => $group->ulid, 'role' => 'suspected']],
        'agencies' => [['agency' => $this->police->ulid, 'formation' => $formation->ulid, 'role' => 'first_responder', 'responded_at' => now()->subHours(2)->format('Y-m-d\TH:i')]],
        'properties' => [['property_type' => $this->vehicle->ulid, 'description' => 'Bus', 'quantity' => 1, 'estimated_value_naira' => 15000000]],
    ]))->assertSessionHasNoErrors()->assertRedirect();

    $incident = onlyIncident();
    expect($incident->status)->toBe(IncidentStatus::Draft)
        ->and($incident->reporting_officer_id)->toBe($this->chief->id)
        ->and($incident->details)->toBe(['victims_count' => 2, 'ransom_demanded' => true])
        ->and($incident->details_schema_version)->toBe($this->type->fresh()->schema_version)
        ->and($incident->lga_id)->toBe($this->lga->id)
        ->and(IncidentCasualty::query()->where('incident_id', $incident->id)->count())->toBe(2)
        ->and(IncidentAgency::query()->where('incident_id', $incident->id)->sole()->agency_formation_id)->toBe($formation->id)
        ->and(IncidentProperty::query()->where('incident_id', $incident->id)->sole()->lga_id)->toBe($this->lga->id);

    // The history tab includes changes to related rows.
    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}?tab=history")
        ->assertInertia(fn (Assert $page) => $page->component('incidents/show')->where('history.total', 7));

    // Drafts list (since 5D: the list's "My drafts" view).
    CrossFixtures::signIn($this->chief)->get('/incidents?statuses[]=draft&mine=1')
        ->assertInertia(fn (Assert $page) => $page->where('incidents.data.0.ulid', $incident->ulid));
});

test('type-specific details are validated against the type', function () {
    saveIncident($this->chief, incidentPayload(['details' => ['ransom_demanded' => true]]))->assertSessionHasErrors('details.victims_count');
    saveIncident($this->chief, incidentPayload(['details' => ['victims_count' => 2, 'weapon' => 'rifle']]))->assertSessionHasErrors('details.weapon');
    saveIncident($this->chief, incidentPayload(['details' => ['victims_count' => 'many']]))->assertSessionHasErrors('details.victims_count');
});

test('a draft saved under an older schema can still be edited; new records use the current fields', function () {
    saveIncident($this->chief, incidentPayload())->assertSessionHasNoErrors();
    $incident = onlyIncident();

    // The field is deprecated after the incident was saved.
    $this->type->forceFill(['details_schema' => [
        ['key' => 'victims_count', 'label' => 'Number of victims', 'type' => 'integer', 'deprecated' => true],
        ['key' => 'ransom_demanded', 'label' => 'Ransom demanded', 'type' => 'boolean'],
        ['key' => 'victims_total', 'label' => 'Victims', 'type' => 'integer'],
    ], 'schema_version' => 2])->save();

    saveIncident($this->chief, incidentPayload(['title' => 'Edited', 'details' => ['victims_count' => 2, 'ransom_demanded' => false]]), $incident)->assertSessionHasNoErrors();
    expect(onlyIncident()->details)->toBe(['victims_count' => 2, 'ransom_demanded' => false])
        ->and(onlyIncident()->details_schema_version)->toBe(2);

    saveIncident($this->chief, incidentPayload(['details' => ['victims_count' => 2]]))->assertSessionHasErrors('details.victims_count');
});

test('occurred_at cannot be in the future; a response cannot precede the incident', function () {
    saveIncident($this->chief, incidentPayload(['occurred_at' => now()->addHours(2)->format('Y-m-d\TH:i')]))->assertSessionHasErrors('occurred_at');

    saveIncident($this->chief, incidentPayload(['agencies' => [[
        'agency' => $this->police->ulid,
        'role' => 'first_responder',
        'responded_at' => now()->subHours(5)->format('Y-m-d\TH:i'),
    ]]]))->assertSessionHasErrors('agencies.0.responded_at');
});

test('casualty rules: agency for security personnel only, a count above zero, one row per outcome and group', function () {
    $row = ['outcome' => 'killed', 'group' => 'civilian', 'agency' => null, 'adult_male' => 1, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0, 'unknown' => 0];

    saveIncident($this->chief, incidentPayload(['casualties' => [[...$row, 'agency' => $this->police->ulid]]]))->assertSessionHasErrors('casualties.0.agency');
    saveIncident($this->chief, incidentPayload(['casualties' => [[...$row, 'group' => 'security_personnel']]]))->assertSessionHasErrors('casualties.0.agency');
    saveIncident($this->chief, incidentPayload(['casualties' => [[...$row, 'adult_male' => 0]]]))->assertSessionHasErrors('casualties.0.adult_male');
    saveIncident($this->chief, incidentPayload(['casualties' => [$row, $row]]))->assertSessionHasErrors('casualties.1.outcome');
    saveIncident($this->chief, incidentPayload(['casualties' => [$row, [...$row, 'outcome' => 'injured']]]))->assertSessionHasNoErrors();
});

test('groups, formations and sites must be visible; the formation must be of the agency', function () {
    $farGroup = CriminalGroup::factory()->in($this->world->lgas['B1'])->create();
    $army = SecurityAgency::factory()->create(['name' => 'Nigerian Army']);
    $armyFormation = AgencyFormation::factory()->of($army)->in($this->lga)->create();
    $secretFormation = AgencyFormation::factory()->of($this->police)->in($this->lga)->classified(Classification::Secret)->create();
    $farSite = OrganizationSite::factory()->of(Organization::factory()->create())->in($this->world->lgas['A1b'])->create();

    saveIncident($this->chief, incidentPayload(['groups' => [['group' => $farGroup->ulid, 'role' => 'suspected']]]))->assertSessionHasErrors('groups.0.group');
    saveIncident($this->chief, incidentPayload(['agencies' => [['agency' => $this->police->ulid, 'formation' => $armyFormation->ulid, 'role' => 'lead']]]))
        ->assertSessionHasErrors(['agencies.0.formation' => 'The formation must belong to Nigeria Police Force.']);
    saveIncident($this->chief, incidentPayload(['agencies' => [['agency' => $this->police->ulid, 'formation' => $secretFormation->ulid, 'role' => 'lead']]]))->assertSessionHasErrors('agencies.0.formation');
    saveIncident($this->chief, incidentPayload(['properties' => [['property_type' => $this->vehicle->ulid, 'site' => $farSite->ulid]]]))->assertSessionHasErrors('properties.0.site');
});

test('linking a person needs link rights: own jurisdiction or an active grant', function () {
    $own = Person::factory()->in($this->lga)->create();
    $neighbour = Person::factory()->in($this->world->lgas['A1b'])->create();
    $granted = CrossFixtures::person($this->world->lgas['B1']);

    saveIncident($this->chief, incidentPayload(['persons' => [['person' => $neighbour->ulid, 'involvement' => 'suspect']]]))->assertSessionHasErrors('persons.0.person');
    saveIncident($this->chief, incidentPayload(['persons' => [['person' => $granted->ulid, 'involvement' => 'suspect']]]))->assertSessionHasErrors('persons.0.person');

    CrossFixtures::grant($this->chief, CrossFixtures::directorOfB1($this->world), GrantScope::Profile);

    saveIncident($this->chief, incidentPayload(['persons' => [
        ['person' => $own->ulid, 'involvement' => 'victim'],
        ['person' => $granted->ulid, 'involvement' => 'suspect'],
    ]]))->assertSessionHasNoErrors();
    $incident = onlyIncident();

    // The Deputy State Director sees the incident but not the granted person's name.
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/incidents/{$incident->ulid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('persons.0.person.ulid', $own->ulid)
            ->where('persons.1.person', null)
            ->where('persons.1.person_hidden', true))
        ->assertDontSee('Danjuma');

    // A DSD editing the draft keeps the hidden link by leaving `person` out.
    $rows = IncidentPerson::query()->where('incident_id', $incident->id)->orderBy('id')->get();
    saveIncident($this->world->users['deputy_state_director'], incidentPayload(['persons' => [
        ['ulid' => $rows[0]->ulid, 'person' => $own->ulid, 'involvement' => 'victim'],
        ['ulid' => $rows[1]->ulid, 'involvement' => 'suspect', 'notes' => 'Seen at the scene'],
    ]]), $incident)->assertSessionHasNoErrors();
    expect($rows[1]->fresh()->person_id)->toBe($granted->id)
        ->and($rows[1]->fresh()->notes)->toBe('Seen at the scene');
});

test('checking an unidentified person and creating a new person record incident_entry match events', function () {
    CrossFixtures::person($this->world->lgas['B1']);
    $client = CrossFixtures::signIn($this->chief);

    $client->postJson('/incidents/person-check', ['first_name' => 'Garba', 'last_name' => 'Musa', 'nin' => CrossFixtures::NIN])
        ->assertOk()
        ->assertJsonCount(1, 'hits')
        ->assertJsonMissingPath('hits.0.ulid');

    $person = ['lga' => $this->lga->ulid, 'first_name' => 'Garba', 'last_name' => 'Musa', 'gender' => 'male', 'nin' => CrossFixtures::NIN, 'classification' => 'restricted'];
    $client->postJson('/incidents/new-person', $person)->assertUnprocessable()->assertJsonValidationErrors('hit_justification');
    $created = $client->postJson('/incidents/new-person', [...$person, 'hit_justification' => 'Different person: confirmed by photograph.'])
        ->assertCreated()->json('person');

    expect(RecordMatchEvent::query()->where('user_id', $this->chief->id)->where('context', MatchContext::IncidentEntry->value)->count())->toBeGreaterThanOrEqual(1)
        ->and(Person::query()->where('ulid', $created['value'])->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'created_despite_hit')->exists())->toBeTrue();
});

test('draft editing: the creator, or the State Director or Deputy of the state; drafts only', function () {
    saveIncident($this->chief, incidentPayload())->assertSessionHasNoErrors();
    $incident = onlyIncident();
    $edit = fn (string $who) => CrossFixtures::signIn($this->world->users[$who])->get("/incidents/{$incident->ulid}/edit")->status();

    expect($edit('lga_chief'))->toBe(200)
        ->and($edit('state_director'))->toBe(200)
        ->and($edit('deputy_state_director'))->toBe(200)
        ->and($edit('director_general'))->toBe(403)
        ->and($edit('ddg_national'))->toBe(403)
        ->and($edit('ddg_zone_b'))->toBe(404)
        ->and($edit('super_admin'))->toBe(404);

    // Another chief of the same state cannot see it.
    CrossFixtures::signIn(CrossFixtures::chiefOf($this->world->lgas['A1b']))->get("/incidents/{$incident->ulid}/edit")->assertNotFound();

    // The SD may move the draft within the state, not outside it.
    saveIncident($this->world->users['state_director'], incidentPayload(['lga' => $this->world->lgas['A1b']->ulid]), $incident)->assertSessionHasNoErrors();
    expect(onlyIncident()->lga_id)->toBe($this->world->lgas['A1b']->id);
    saveIncident($this->world->users['state_director'], incidentPayload(['lga' => $this->world->lgas['B1']->ulid]), $incident)->assertSessionHasErrors('lga');

    CrossFixtures::system();
    // Since 5C a submitted incident stays editable until verified; closed
    // incidents are locked.
    $incident->forceFill(['status' => IncidentStatus::Submitted, 'submitted_at' => now(), 'reference_no' => 'BUF/T/TST/2026/000001'])->save();
    expect($edit('state_director'))->toBe(200);
    DB::table('incidents')->where('id', $incident->id)->update(['status' => IncidentStatus::Closed->value]);
    expect($edit('state_director'))->toBe(403);
    saveIncident($this->world->users['state_director'], incidentPayload(['amendment_reason' => 'Attempt on a closed incident.']), $incident)->assertForbidden();
});

test('an access request may cite an incident the requester can see; the approver sees only a summary line', function () {
    CrossFixtures::person($this->world->lgas['B1']);
    saveIncident($this->chief, incidentPayload())->assertSessionHasNoErrors();
    $own = onlyIncident();
    $hidden = Incident::factory()->in($this->world->lgas['A1b'])->create();
    $token = fn () => CrossFixtures::byNin($this->chief)->hits[0]->token;
    $request = fn (Incident $incident) => CrossFixtures::signIn($this->chief)->post('/access-requests', [
        ...CrossFixtures::requestData(),
        'token' => $token(),
        'incident' => $incident->ulid,
    ]);

    $request($hidden)->assertSessionHasErrors('incident');
    $request($own)->assertSessionHasNoErrors();

    CrossFixtures::system();
    $accessRequest = RecordAccessRequest::query()->latest('id')->firstOrFail();
    expect($accessRequest->incident_id)->toBe($own->id);

    $director = CrossFixtures::directorOfB1($this->world);
    app(JurisdictionContext::class)->actingAs($director, fn () => expect(Incident::query()->count())->toBe(0));

    CrossFixtures::signIn($director)->get("/access-requests/{$accessRequest->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('request.related_incident', "No reference yet — Kidnapping, {$this->lga->name} LGA"))
        ->assertDontSee($own->ulid)
        ->assertDontSee('Travellers abducted');
});

test('the incident policy by role: view within jurisdiction, create, report in another state, update drafts', function (string $who, bool $view, bool $create, bool $inB1, bool $update) {
    $incident = Incident::factory()->in($this->lga)->by($this->chief)->create();
    $user = $this->world->users[$who];

    app(JurisdictionContext::class)->actingAs($user, function () use ($user, $incident, $view, $create, $inB1, $update) {
        expect($user->can('view', $incident))->toBe($view)
            ->and($user->can('create', Incident::class))->toBe($create)
            ->and($user->can('update', $incident))->toBe($update)
            ->and($user->can('createIn', [Incident::class, $this->world->lgas['B1']]))->toBe($inB1);
    });
})->with([
    'super admin' => ['super_admin', false, false, false, false],
    'director general' => ['director_general', true, true, true, false],
    'national DDG' => ['ddg_national', true, true, true, false],
    'DDG of another zone' => ['ddg_zone_b', false, true, true, false],
    'state director' => ['state_director', true, true, false, true],
    'deputy state director' => ['deputy_state_director', true, true, false, true],
    'LGA chief (creator)' => ['lga_chief', true, true, false, true],
]);

test('an editor who cannot see a linked group keeps it by leaving `group` out', function () {
    // The State Director links a group recorded in another LGA of the state.
    $group = CriminalGroup::factory()->in($this->world->lgas['A1b'])->create();
    saveIncident($this->chief, incidentPayload())->assertSessionHasNoErrors();
    $incident = onlyIncident();
    saveIncident($this->world->users['state_director'], incidentPayload(['groups' => [['group' => $group->ulid, 'role' => 'claimed']]]), $incident)->assertSessionHasNoErrors();

    $row = IncidentCriminalGroup::query()->where('incident_id', $incident->id)->sole();

    // The chief cannot see it, so it is shown without its name.
    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.group', null)->where('groups.0.group_hidden', true));

    saveIncident($this->chief, incidentPayload(['groups' => [['ulid' => $row->ulid, 'role' => 'confirmed']]]), $incident)->assertSessionHasNoErrors();
    expect($row->fresh()->criminal_group_id)->toBe($group->id)
        ->and($row->fresh()->role->value)->toBe('confirmed');

    // Leaving `group` out is only allowed for an existing row.
    saveIncident($this->chief, incidentPayload(['groups' => [['role' => 'confirmed']]]), $incident)->assertSessionHasErrors('groups.0.group');
});
