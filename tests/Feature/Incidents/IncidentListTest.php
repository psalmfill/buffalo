<?php

use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentCasualty;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentSavedFilter;
use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
});

/** A submitted incident with a title, in $lga (default A1a). */
function listed(string $title, array $attributes = [], ?string $lga = 'A1a'): Incident
{
    $world = test()->world;

    return W::at(W::draft($world->lgas[$lga], $world->users['state_director'], ['title' => $title, ...$attributes]), IncidentStatus::Submitted, array_intersect_key($attributes, array_flip(['submitted_at'])));
}

/** Titles on the list for $user with $query. */
function titles(User $user, array $query = []): array
{
    $titles = [];
    CrossFixtures::signIn($user)->get('/incidents?'.http_build_query($query))->assertOk()
        ->assertInertia(function (Assert $page) use (&$titles) {
            $titles = array_column($page->toArray()['props']['incidents']['data'], 'title');
        });

    return $titles;
}

test('the list shows only incidents within reach and clearance; never others\' drafts', function () {
    listed('Own LGA');
    listed('Same state', [], 'A1b');
    listed('Other zone', [], 'B1');
    listed('Secret here', ['classification' => Classification::Secret]);
    W::draft($this->lga, $this->sd, ['title' => 'Someone else draft']);
    W::draft($this->lga, $this->chief, ['title' => 'My draft']);

    expect(titles($this->chief))->toEqualCanonicalizing(['Own LGA'])
        ->and(titles($this->sd))->toEqualCanonicalizing(['Own LGA', 'Same state', 'Secret here'])
        ->and(titles($this->world->users['director_general']))->toEqualCanonicalizing(['Own LGA', 'Same state', 'Other zone', 'Secret here'])
        // Drafts: only one's own, even when asking for drafts.
        ->and(titles($this->chief, ['statuses' => ['draft']]))->toBe(['My draft']);

    CrossFixtures::signIn($this->world->users['super_admin'])->get('/incidents')->assertForbidden();
});

test('the default status filter leaves out drafts, closed and invalid incidents', function () {
    foreach (IncidentStatus::cases() as $status) {
        $incident = W::draft($this->lga, $this->chief, ['title' => $status->value]);
        if ($status !== IncidentStatus::Draft) {
            W::at($incident, $status, $status === IncidentStatus::Invalid ? ['invalid_reason' => 'false_report'] : []);
        }
    }

    expect(titles($this->chief))->toEqualCanonicalizing(['submitted', 'returned', 'verified', 'under_investigation', 'resolved'])
        ->and(titles($this->chief, ['statuses' => ['closed', 'invalid']]))->toEqualCanonicalizing(['closed', 'invalid']);
});

test('each filter narrows the list', function () {
    $robbery = IncidentType::factory()->create(['category' => IncidentCategory::Crime]);
    $flood = IncidentType::factory()->create(['category' => IncidentCategory::DisasterEnvironmental]);
    $community = Community::factory()->in($this->lga)->create();
    $group = CriminalGroup::factory()->in($this->lga)->create();

    $a = listed('Robbery critical', ['incident_type_id' => $robbery->id, 'severity' => Severity::Critical, 'occurred_at' => now()->subDays(2), 'community_id' => $community->id, 'escalated_at' => now(), 'location' => Incident::pointFrom(6.5, 3.4)]);
    $b = listed('Flood low', ['incident_type_id' => $flood->id, 'severity' => Severity::Low, 'occurred_at' => now()->subDays(20), 'escalated_at' => now(), 'escalation_acknowledged_at' => now()]);
    $c = listed('Other place', ['incident_type_id' => $robbery->id, 'occurred_at' => now()->subDays(1), 'reporting_officer_id' => $this->chief->id], 'A1b');

    IncidentCasualty::query()->forceCreate(['incident_id' => $a->id, 'state_id' => $a->state_id, 'lga_id' => $a->lga_id, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 3]);
    IncidentCasualty::query()->forceCreate(['incident_id' => $b->id, 'state_id' => $b->state_id, 'lga_id' => $b->lga_id, 'outcome' => 'abducted', 'group' => 'civilian', 'adult_female' => 2]);
    IncidentCriminalGroup::query()->forceCreate(['incident_id' => $a->id, 'state_id' => $a->state_id, 'lga_id' => $a->lga_id, 'criminal_group_id' => $group->id, 'role' => 'suspected']);

    $sd = $this->sd;
    expect(titles($sd, ['types' => [$robbery->ulid]]))->toEqualCanonicalizing(['Robbery critical', 'Other place'])
        ->and(titles($sd, ['category' => 'disaster_environmental']))->toBe(['Flood low'])
        ->and(titles($sd, ['severities' => ['critical', 'low']]))->toEqualCanonicalizing(['Robbery critical', 'Flood low'])
        ->and(titles($sd, ['escalated' => 'yes']))->toEqualCanonicalizing(['Robbery critical', 'Flood low'])
        ->and(titles($sd, ['escalated' => 'unacknowledged']))->toBe(['Robbery critical'])
        ->and(titles($sd, ['escalated' => 'no']))->toBe(['Other place'])
        ->and(titles($sd, ['from' => now('Africa/Lagos')->subDays(5)->toDateString()]))->toEqualCanonicalizing(['Robbery critical', 'Other place'])
        ->and(titles($sd, ['to' => now('Africa/Lagos')->subDays(10)->toDateString()]))->toBe(['Flood low'])
        ->and(titles($sd, ['lga' => $this->world->lgas['A1b']->ulid]))->toBe(['Other place'])
        ->and(titles($this->world->users['director_general'], ['state' => $this->world->states['A1']->ulid]))->toEqualCanonicalizing(['Robbery critical', 'Flood low', 'Other place'])
        ->and(titles($sd, ['community' => $community->ulid]))->toBe(['Robbery critical'])
        ->and(titles($sd, ['has_casualties' => '1']))->toEqualCanonicalizing(['Robbery critical', 'Flood low'])
        ->and(titles($sd, ['outcome' => 'abducted']))->toBe(['Flood low'])
        ->and(titles($sd, ['officer' => $this->chief->ulid]))->toBe(['Other place'])
        ->and(titles($sd, ['group' => $group->ulid]))->toBe(['Robbery critical'])
        ->and(titles($sd, ['no_location' => '1']))->toEqualCanonicalizing(['Flood low', 'Other place']);
});

test('overdue verification and "my reports"', function () {
    config(['buffalo.incidents.verify_within_minutes.high' => 60]);
    listed('Overdue', ['severity' => Severity::High, 'submitted_at' => now()->subHours(2)]);
    listed('Fresh', ['severity' => Severity::High, 'submitted_at' => now()->subMinutes(5)]);
    W::at(W::draft($this->lga, $this->chief, ['title' => 'Mine']), IncidentStatus::Submitted);

    expect(titles($this->sd, ['overdue' => '1']))->toBe(['Overdue'])
        ->and(titles($this->chief, ['mine' => '1']))->toBe(['Mine']);
});

test('search: a reference number exactly and without case; otherwise ranked full text', function () {
    $ref = listed('Gunmen attack bus at the toll gate', ['description' => 'Gunmen abducted travellers from a bus near the toll gate.']);
    DB::table('incidents')->where('id', $ref->id)->update(['reference_no' => 'BUF/LA/IKJ/2026/000123']);
    listed('Flooding near the market', ['description' => 'Heavy rain flooded the market road.']);
    listed('Lorry accident', ['description' => 'A lorry hit a parked bus at the junction late in the evening.']);

    expect(titles($this->sd, ['q' => 'buf/la/ikj/2026/000123']))->toBe(['Gunmen attack bus at the toll gate'])
        ->and(titles($this->sd, ['q' => 'BUF/LA/IKJ/2026/000124']))->toBe([])
        ->and(titles($this->sd, ['q' => 'flooded market']))->toBe(['Flooding near the market'])
        // Ranked: the incident mentioning the bus most comes first.
        ->and(titles($this->sd, ['q' => 'bus']))->toBe(['Gunmen attack bus at the toll gate', 'Lorry accident']);
});

test('sorting and pagination', function () {
    foreach (range(1, 27) as $n) {
        listed("Incident {$n}", ['occurred_at' => now()->subHours($n), 'severity' => $n === 27 ? Severity::Critical : Severity::Low]);
    }

    $page1 = titles($this->sd);
    expect($page1)->toHaveCount(25)->and($page1[0])->toBe('Incident 1')
        ->and(titles($this->sd, ['page' => 2]))->toBe(['Incident 26', 'Incident 27'])
        ->and(titles($this->sd, ['sort' => 'occurred_at', 'dir' => 'asc'])[0])->toBe('Incident 27')
        ->and(titles($this->sd, ['sort' => 'severity'])[0])->toBe('Incident 27');
});

test('rows carry a casualty summary and the reporting officer; never numeric ids', function () {
    $incident = listed('With casualties');
    IncidentCasualty::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 3]);
    IncidentCasualty::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'outcome' => 'abducted', 'group' => 'civilian', 'adult_female' => 2]);

    CrossFixtures::signIn($this->sd)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data.0.casualties', '3 killed, 2 abducted')
        ->where('incidents.data.0.reporting_officer', $this->sd->name)
        ->missing('incidents.data.0.id'));
});

test('saved filters: per user, through validation; a shared URL shows each viewer their own scope', function () {
    listed('Critical here', ['severity' => Severity::Critical, 'escalated_at' => now()]);
    listed('Critical secret', ['severity' => Severity::Critical, 'escalated_at' => now(), 'classification' => Classification::Secret]);

    CrossFixtures::signIn($this->sd)->post('/incidents/saved-filters', [
        'name' => 'Critical escalations',
        'filters' => ['severities' => ['critical'], 'escalated' => 'unacknowledged', 'bogus' => 'x', 'statuses' => ['nope']],
    ])->assertSessionHasNoErrors();

    $saved = IncidentSavedFilter::query()->sole();
    expect($saved->user_id)->toBe($this->sd->id)
        ->and($saved->filters)->toEqual(['severities' => ['critical'], 'escalated' => 'unacknowledged']);

    // Only the owner sees and removes it.
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get('/incidents')->assertInertia(fn (Assert $page) => $page->where('savedFilters', []));
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->delete("/incidents/saved-filters/{$saved->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->sd)->get('/incidents')->assertInertia(fn (Assert $page) => $page->where('savedFilters.0.name', 'Critical escalations'));

    // The same URL: the DSD sees only what their clearance allows.
    $query = $saved->filters;
    expect(titles($this->sd, $query))->toEqualCanonicalizing(['Critical here', 'Critical secret'])
        ->and(titles($this->world->users['deputy_state_director'], $query))->toBe(['Critical here']);

    CrossFixtures::signIn($this->sd)->delete("/incidents/saved-filters/{$saved->ulid}")->assertSessionHasNoErrors();
    expect(IncidentSavedFilter::query()->count())->toBe(0);
});

test('built-in views by role', function () {
    $names = fn (User $user) => array_column(CrossFixtures::signIn($user)->get('/incidents')->inertiaProps('defaultFilters'), 'name');

    expect($names($this->sd))->toBe(['Awaiting my verification', 'My reports', 'My drafts'])
        ->and($names($this->world->users['director_general']))->toBe(['Escalated, unacknowledged', 'My reports', 'My drafts'])
        ->and($names($this->chief))->toBe(['My reports', 'My drafts']);
});

test('the map endpoint returns popup fields only, a missing-location count and a cap', function () {
    listed('Located A', ['location' => Incident::pointFrom(6.5, 3.4), 'description' => 'Secret details about Musa']);
    listed('Located B', ['location' => Incident::pointFrom(6.6, 3.5)]);
    listed('Located C', ['location' => Incident::pointFrom(6.7, 3.6)]);
    listed('No location');
    listed('Old', ['location' => Incident::pointFrom(6.5, 3.4), 'occurred_at' => now()->subDays(45)]);

    $data = CrossFixtures::signIn($this->sd)->getJson('/incidents/map/data')->assertOk()->json();

    expect($data['total'])->toBe(3)
        ->and($data['missing_location'])->toBe(1)
        ->and($data['capped'])->toBeFalse()
        ->and(array_keys($data['points'][0]))->toEqualCanonicalizing(['ulid', 'reference_no', 'type', 'category', 'severity', 'status', 'occurred_at', 'lat', 'lng'])
        ->and(json_encode($data))->not->toContain('Musa')->not->toContain('Located');

    config(['buffalo.incidents.map_cap' => 2]);
    $capped = CrossFixtures::signIn($this->sd)->getJson('/incidents/map/data')->json();
    expect($capped['points'])->toHaveCount(2)->and($capped['capped'])->toBeTrue()->and($capped['total'])->toBe(3);

    // An explicit range includes older incidents; out-of-reach ones never appear.
    expect(CrossFixtures::signIn($this->sd)->getJson('/incidents/map/data?from='.now()->subDays(60)->toDateString())->json('total'))->toBe(4)
        ->and(CrossFixtures::signIn($this->chief)->getJson('/incidents/map/data')->json('total'))->toBe(3)
        ->and(CrossFixtures::signIn(User::factory()->stateDirector($this->world->states['B1'])->create())->getJson('/incidents/map/data')->json('total'))->toBe(0);
});
