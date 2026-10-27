<?php

use App\Actions\Users\OfficerPostings;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\PoliticalOfficeType;
use App\Enums\SecurityRelevance;
use App\Enums\ThreatUrgency;
use App\Models\AgencyFormation;
use App\Models\IncidentType;
use App\Models\LgaProfile;
use App\Models\OrganizationSite;
use App\Models\PoliticalOffice;
use App\Models\StateProfile;
use App\Models\User;
use App\Notifications\StateProfileReviewDue;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Lens\StateLens;
use App\Support\LocalGovernment\StateFacts;
use App\Support\LocalGovernment\StateProfileCompleteness;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 11F: state pages and profiles. World: zone A with states A1 (LGAs
| A1a, A1b) and A2 (LGA A2); zone B with B1 (LGA B1). The world's SD, DSD
| and LGA chief belong to A1 / A1a.
*/

const REFERENCE_TABS = ['overview', 'profile', 'lgas', 'districts', 'leadership'];

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->a1 = $this->world->states['A1'];
    $this->dg = $this->world->users['director_general'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chief = $this->world->users['lga_chief'];
});

/*
|--------------------------------------------------------------------------
| 11F.1 — Visibility and editing
|--------------------------------------------------------------------------
*/

/** Expected visible state profiles per world user (written out from the prompt and CLAUDE.md section 4). */
const STATE_PROFILE_EXPECTED = [
    'super_admin' => [],
    'director_general' => ['A1', 'A2', 'B1'],
    'ddg_national' => ['A1', 'A2', 'B1'],
    'ddg_zone_a' => ['A1', 'A2'],
    'ddg_zone_b' => ['B1'],
    'ddg_zones_a_b' => ['A1', 'A2', 'B1'],
    'ddg_empty_zone' => [],
    'state_director' => ['A1'],
    'deputy_state_director' => ['A1'],
    // The LGA chief reads their own state's profile (LgaVisibilityRule).
    'lga_chief' => ['A1'],
    'inactive_dg' => [],
    'locked_dg' => [],
    'deleted_dg' => [],
    'no_role' => [],
    'inconsistent_state_director' => [],
];

test('state profiles follow the jurisdiction matrix, with the LGA chief reading their own state', function (string $who) {
    foreach (['A1', 'A2', 'B1'] as $state) {
        StateProfile::factory()->forState($this->world->states[$state])->create(['general_notes' => $state]);
    }
    $user = $this->world->users[$who];

    $visible = app(JurisdictionContext::class)->actingAs($user->fresh() ?? User::withTrashed()->findOrFail($user->id),
        fn () => StateProfile::query()->get()->map(fn (StateProfile $p) => strip_tags((string) $p->general_notes))->sort()->values()->all());

    expect($visible)->toBe(STATE_PROFILE_EXPECTED[$who]);
})->with(array_keys(STATE_PROFILE_EXPECTED));

test('the single-record gate agrees with the scope in both directions for the LGA chief', function () {
    $own = StateProfile::factory()->forState($this->a1)->create();
    $other = StateProfile::factory()->forState($this->world->states['A2'])->create();
    $gate = app(JurisdictionGate::class);

    expect($gate->canAccess($this->chief, $own))->toBeTrue()
        ->and($gate->canAccess($this->chief, $other))->toBeFalse()
        ->and($gate->canAccess($this->sd, $other))->toBeFalse();
});

test('state-level and national roles edit the profile and capital; LGA chiefs and super admins cannot', function () {
    $payload = fn (string $notes) => [
        'history' => '<p>Founded 1967.</p>', 'economic_activities' => '<p>Trade</p>', 'terrain' => ['coastal', 'urban'],
        'major_roads' => '<p>Lagos–Ibadan Expressway</p>', 'waterways' => null,
        'borders' => '<p>Ogun State; Benin Republic at Seme</p>', 'general_notes' => "<p>{$notes}</p>", 'capital' => 'Ikeja',
    ];
    $url = "/states/{$this->a1->ulid}/profile";

    CrossFixtures::signIn($this->sd)->put($url, $payload('by the SD'))->assertSessionHasNoErrors()->assertRedirect();
    $profile = StateProfile::query()->sole();
    expect($profile->terrain)->toBe(['urban', 'coastal'])
        ->and($profile->borders)->toContain('Benin Republic')
        ->and($profile->getAttributes()['borders_text'])->toBe('Ogun State; Benin Republic at Seme')
        ->and($profile->created_by)->toBe($this->sd->id)
        ->and($this->a1->refresh()->capital)->toBe('Ikeja');

    // Audited (profile and the state's capital).
    expect(Activity::query()->where('subject_type', $profile->getMorphClass())->where('subject_id', $profile->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_type', $this->a1->getMorphClass())->where('subject_id', $this->a1->id)->exists())->toBeTrue();

    CrossFixtures::signIn($this->dsd)->put($url, $payload('by the DSD'))->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->put($url, $payload('by the DDG'))->assertSessionHasNoErrors();
    expect(strip_tags((string) $profile->refresh()->general_notes))->toBe('by the DDG');

    CrossFixtures::signIn($this->chief)->put($url, $payload('by the chief'))->assertForbidden();
    CrossFixtures::signIn($this->chief)->post("/states/{$this->a1->ulid}/review")->assertForbidden();
    CrossFixtures::signIn($this->world->users['super_admin'])->put($url, $payload('by the admin'))->assertForbidden();
    // Outside reach: 404.
    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->put($url, $payload('by zone B'))->assertNotFound();
    CrossFixtures::signIn($this->sd)->put("/states/{$this->world->states['A2']->ulid}/profile", $payload('A2'))->assertNotFound();
    expect(strip_tags((string) $profile->refresh()->general_notes))->toBe('by the DDG');

    // Names, codes and zones are never editable here.
    $name = $this->a1->name;
    CrossFixtures::signIn($this->sd)->put($url, [...$payload('x'), 'name' => 'Renamed', 'code' => 'ZZ'])->assertSessionHasNoErrors();
    expect($this->a1->refresh()->name)->toBe($name);
});

test('marking the profile as reviewed records who and when', function () {
    $this->travelTo(now()->setTime(9, 30));
    CrossFixtures::signIn($this->sd)->post("/states/{$this->a1->ulid}/review")->assertRedirect();

    $profile = StateProfile::query()->sole();
    expect($profile->last_reviewed_by)->toBe($this->sd->id)
        ->and($profile->last_reviewed_at?->equalTo(now()))->toBeTrue();
});

test('completeness counts history, economy, terrain, roads, borders and a reviewed LGA profile', function () {
    $profile = StateProfile::factory()->forState($this->a1)->create(['history' => '<p>Old</p>', 'terrain' => ['urban'], 'borders' => '<p> </p>']);

    expect(StateProfileCompleteness::missing($profile, 0))->toBe(['economic_activities', 'major_roads', 'borders', 'lga_profiles'])
        ->and(StateProfileCompleteness::percent($profile, 0))->toBe(33)
        ->and(StateProfileCompleteness::percent(null, 0))->toBe(0);

    LgaProfile::factory()->forLga($this->world->lgas['A1b'])->create(['last_reviewed_at' => now()]);
    $profile->update(['economic_activities' => '<p>Trade</p>', 'major_roads' => '<p>A1</p>', 'borders' => '<p>B</p>']);

    $reviewed = app(JurisdictionContext::class)->actingAs($this->sd, fn () => StateProfileCompleteness::reviewedLgaProfiles($this->a1));
    expect($reviewed)->toBe(1)
        ->and(StateProfileCompleteness::percent($profile->refresh(), $reviewed))->toBe(100);
});

test('area and population are the sums of the LGAs, with the number missing each figure', function () {
    $this->world->lgas['A1a']->forceFill(['area_km2' => 120.5, 'population_estimate' => 300000])->save();
    $this->world->lgas['A1b']->forceFill(['area_km2' => 79.5, 'population_estimate' => null])->save();

    expect(StateFacts::for($this->a1))->toMatchArray([
        'area_km2' => 200.0, 'area_missing' => 0,
        'population' => 300000, 'population_missing' => 1,
        'lgas' => 2, 'districts' => 2,
    ]);

    CrossFixtures::signIn($this->sd)->get("/states/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('overview.facts.area_km2', 200)
        ->where('overview.facts.population_missing', 1));
});

test('the weekly reminder reaches the State Director and Deputy of states not reviewed recently', function () {
    $staleSd = CrossFixtures::directorOfB1($this->world);
    StateProfile::factory()->forState($this->world->states['B1'])->create(['last_reviewed_at' => now()->subDays(200)]);
    $freshSd = User::factory()->stateDirector($this->world->states['A2'])->create();
    StateProfile::factory()->forState($this->world->states['A2'])->create(['last_reviewed_at' => now()->subDays(5)]);

    $this->artisan('buffalo:profile-review-reminders')->assertSuccessful();

    Notification::assertSentTo($this->sd, StateProfileReviewDue::class, fn ($n) => $n->state->is($this->a1)
        && $n->toArray($this->sd)['url'] === "/states/{$this->a1->ulid}?tab=profile");
    Notification::assertSentTo($this->dsd, StateProfileReviewDue::class);
    Notification::assertSentTo($staleSd, StateProfileReviewDue::class);
    Notification::assertNotSentTo($freshSd, StateProfileReviewDue::class);
    Notification::assertNotSentTo($this->chief, StateProfileReviewDue::class);
    Notification::assertNotSentTo($this->dg, StateProfileReviewDue::class);
});

/*
|--------------------------------------------------------------------------
| 11F.2 — /states per role
|--------------------------------------------------------------------------
*/

test('national users list the states within reach; a zoned DDG only their zones', function () {
    CrossFixtures::signIn($this->dg)->get('/states')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('states/index')
        ->where('states', fn ($rows) => collect($rows)->pluck('ulid')->sort()->values()->all() === collect($this->world->states)->pluck('ulid')->sort()->values()->all()));

    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->get('/states')->assertInertia(fn (Assert $page) => $page
        ->where('states', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === collect([$this->a1->name, $this->world->states['A2']->name])->sort()->values()->all()));

    // A state outside reach cannot be filtered to.
    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->get('/states?state='.$this->world->states['B1']->ulid)->assertSessionHasErrors('state');
});

test('state roles and LGA chiefs go straight to their own state; super admins are refused', function () {
    foreach (['sd', 'dsd', 'chief'] as $who) {
        CrossFixtures::signIn($this->{$who})->get('/states')->assertRedirect("/states/{$this->a1->ulid}");
    }

    CrossFixtures::signIn($this->world->users['super_admin'])->get('/states')->assertForbidden();
    CrossFixtures::signIn($this->world->users['super_admin'])->get("/states/{$this->a1->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->chief)->get("/states/{$this->world->states['A2']->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->get("/states/{$this->world->states['B1']->ulid}")->assertNotFound();
});

test('the state lens is the list\'s default filter, and an explicit filter wins', function () {
    $this->actingAs($this->dg)->withSession([StateLens::SESSION_KEY => ['type' => 'state', 'ulid' => $this->a1->ulid]]);

    $this->get('/states')->assertRedirect('/states?state='.$this->a1->ulid);
    $this->get('/states?state='.$this->a1->ulid)->assertInertia(fn (Assert $page) => $page
        ->where('states', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$this->a1->ulid]));
    $this->get('/states?lens=off')->assertInertia(fn (Assert $page) => $page->where('states', fn ($rows) => count($rows) === 3));
});

test('the list shows the State Director, completeness and figures through the viewer\'s scopes', function () {
    app(OfficerPostings::class)->open($this->sd);
    StateProfile::factory()->forState($this->a1)->create(['history' => '<p>x</p>', 'last_reviewed_at' => now()]);
    $type = IncidentType::factory()->create();
    W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $type->id, 'occurred_at' => now()->subDays(3)]), IncidentStatus::Verified);
    W::at(W::draft($this->world->lgas['A1b'], $this->chief, ['incident_type_id' => $type->id, 'occurred_at' => now()->subDays(3)]), IncidentStatus::Submitted);
    W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $type->id, 'occurred_at' => now()->subDays(3)]);
    W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $type->id, 'occurred_at' => now()->subDays(40)]), IncidentStatus::Verified);
    T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate]);
    T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Routine]);

    CrossFixtures::signIn($this->dg)->get('/states')->assertInertia(fn (Assert $page) => $page->where('states', function ($rows) {
        $a1 = collect($rows)->firstWhere('ulid', $this->a1->ulid);
        $b1 = collect($rows)->firstWhere('ulid', $this->world->states['B1']->ulid);

        return $a1['director'] === $this->sd->name && $a1['completeness'] === 17 && $a1['last_reviewed_at'] !== null
            && $a1['incidents30'] === 2 && $a1['immediate_threats'] === 1 && $a1['lgas'] === 2
            && $b1['director'] === null && $b1['incidents30'] === 0;
    }));
});

/*
|--------------------------------------------------------------------------
| 11F.2 — Tabs
|--------------------------------------------------------------------------
*/

test('LGA chiefs get the reference tabs only; the operational tabs are hidden, not empty', function () {
    CrossFixtures::signIn($this->chief)->get("/states/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('states/show')
        ->where('tabs', REFERENCE_TABS)
        ->where('can.operational', false)
        ->where('can.updateProfile', false)
        ->where('overview.dashboardUrl', null)
        ->where('overview.officers', null)
        ->missing('overview.facts.area_km2')
        ->missing('overview.facts.communities'));

    foreach (['security_presence', 'organizations', 'incidents', 'history'] as $tab) {
        CrossFixtures::signIn($this->chief)->get("/states/{$this->a1->ulid}?tab={$tab}")->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'overview')
            ->where('securityPresence', null)->where('organizations', null)->where('incidents', null)->where('history', null));
    }

    CrossFixtures::signIn($this->sd)->get("/states/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('tabs', [...REFERENCE_TABS, 'security_presence', 'organizations', 'incidents', 'history'])
        ->where('overview.dashboardUrl', '/dashboard'));

    CrossFixtures::signIn($this->dg)->get("/states/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('overview.dashboardUrl', "/dashboard/states/{$this->a1->ulid}"));
});

test('the LGAs tab lists only the chief\'s own LGA for an LGA chief', function () {
    $lgas = fn (User $user) => collect(CrossFixtures::signIn($user)->get("/states/{$this->a1->ulid}?tab=lgas")->viewData('page')['props']['lgas'])->pluck('ulid')->sort()->values()->all();

    expect($lgas($this->chief))->toBe([$this->world->lgas['A1a']->ulid])
        ->and($lgas($this->sd))->toBe(collect([$this->world->lgas['A1a']->ulid, $this->world->lgas['A1b']->ulid])->sort()->values()->all());
});

test('the districts tab lists the state\'s districts with their LGAs and current senators', function () {
    $lga = $this->world->lgas['A1a'];
    $senator = CrossFixtures::person($lga, ['first_name' => 'Bola', 'last_name' => 'Senator', 'other_names' => null]);
    PoliticalOffice::factory()->forPerson($senator)->senator($lga->senatorialDistrict)->create();

    CrossFixtures::signIn($this->chief)->get("/states/{$this->a1->ulid}?tab=districts")->assertInertia(fn (Assert $page) => $page
        ->has('districts', 2)
        ->where('districts', fn ($districts) => collect($districts)->firstWhere('ulid', $lga->senatorialDistrict->ulid)['senators'][0]['name'] === 'Bola Senator'));
});

test('the leadership tab shows current state-level office holders by office, past ones apart', function () {
    $lga = $this->world->lgas['A1a'];
    $person = fn (string $last) => CrossFixtures::person($lga, ['first_name' => 'Ade', 'last_name' => $last, 'other_names' => null]);
    PoliticalOffice::factory()->forPerson($person('Governor'))->stateWide(PoliticalOfficeType::Governor, $this->a1)->create();
    PoliticalOffice::factory()->forPerson($person('Commissioner'))->stateWide(PoliticalOfficeType::Commissioner, $this->a1)->create();
    PoliticalOffice::factory()->forPerson($person('Formergov'))->stateWide(PoliticalOfficeType::Governor, $this->a1)->ended()->create();
    PoliticalOffice::factory()->forPerson($person('Partyman'))->stateWide(PoliticalOfficeType::PartyOfficial, $this->a1)->create();
    PoliticalOffice::factory()->forPerson($person('Chairman'))->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();
    PoliticalOffice::factory()->forPerson(CrossFixtures::person($this->world->lgas['A2'], ['last_name' => 'Elsewhere']))->stateWide(PoliticalOfficeType::Governor, $this->world->states['A2'])->create();

    $leadership = fn (User $user) => CrossFixtures::signIn($user)->get("/states/{$this->a1->ulid}?tab=leadership")->viewData('page')['props']['leadership'];

    $sd = $leadership($this->sd);
    expect(collect($sd['groups'])->pluck('office.value')->all())->toBe(['governor', 'commissioner', 'party_official'])
        ->and(collect($sd['groups'])->flatMap(fn ($g) => collect($g['holders'])->pluck('person.name'))->all())->toBe(['Ade Governor', 'Ade Commissioner', 'Ade Partyman'])
        ->and(collect($sd['past'])->pluck('person.name')->all())->toBe(['Ade Formergov']);

    // The chief: public office holders only (and only those their scopes show).
    $chief = $leadership($this->chief);
    expect(collect($chief['groups'])->pluck('office.value')->all())->not->toContain('party_official')
        ->and(collect($chief['groups'])->flatMap(fn ($g) => collect($g['holders'])->pluck('person.name'))->all())->not->toContain('Ade Partyman', 'Ade Chairman');
});

test('the security presence tab follows the viewer\'s scopes and clearance', function () {
    $a1a = $this->world->lgas['A1a'];
    AgencyFormation::factory()->in($a1a)->create(['name' => 'Ikeja Area Command', 'formation_type' => 'area_command']);
    AgencyFormation::factory()->in($a1a)->create(['name' => 'Aaa Checkpoint', 'formation_type' => 'checkpoint']);
    AgencyFormation::factory()->in($this->world->lgas['A2'])->create(['name' => 'Neighbour Station']);
    F::flashpoint($a1a, ['name' => 'Toll gate corridor']);
    F::flashpoint($a1a, ['name' => 'Covert corridor'], Classification::Secret);
    F::flashpoint($this->world->lgas['A2'], ['name' => 'Neighbour corridor']);
    T::threat($a1a, ['title' => 'Planned attack', 'urgency' => ThreatUrgency::Immediate]);

    $presence = fn (User $user) => CrossFixtures::signIn($user)->get("/states/{$this->a1->ulid}?tab=security_presence")->viewData('page')['props']['securityPresence'];

    $sd = $presence($this->sd);
    $formations = collect($sd['formations'])->flatMap(fn ($g) => collect($g['formations'])->pluck('name'))->all();
    $flashpoints = collect($sd['flashpoints'])->flatMap(fn ($g) => collect($g['rows'])->pluck('name'))->all();
    expect($formations)->toContain('Ikeja Area Command')->not->toContain('Neighbour Station')
        ->and(array_search('Ikeja Area Command', $formations, true))->toBeLessThan(array_search('Aaa Checkpoint', $formations, true))
        ->and($flashpoints)->toContain('Toll gate corridor', 'Covert corridor')->not->toContain('Neighbour corridor')
        ->and($sd['threats'][0]['value'])->toBe('immediate')
        ->and($sd['threats'][0]['rows'][0]['title'])->toBe('Planned attack');

    $dsd = $presence($this->dsd);
    expect(collect($dsd['flashpoints'])->flatMap(fn ($g) => collect($g['rows'])->pluck('name'))->all())->not->toContain('Covert corridor');
});

test('disseminations received by the state are listed for its directors and national users', function () {
    $threat = T::threat($this->world->lgas['B1'], ['title' => 'Cross-border warning']);
    $owner = CrossFixtures::directorOfB1($this->world);
    CrossFixtures::signIn($owner)->post("/threats/{$threat->ulid}/disseminations", [
        'targets' => [['level' => 'state', 'ulid' => $this->a1->ulid]],
        'reason' => 'The gang is expected to move into A1 this week.',
    ])->assertSessionHasNoErrors();

    $received = fn (User $user) => CrossFixtures::signIn($user)->get("/states/{$this->a1->ulid}?tab=security_presence")->viewData('page')['props']['securityPresence']['disseminations'];

    expect(collect($received($this->sd))->pluck('label')->all())->toBe([$threat->reference_no])
        ->and(collect($received($this->dg))->pluck('label')->all())->toBe([$threat->reference_no]);
});

test('the organizations tab lists high and critical-infrastructure sites by LGA', function () {
    OrganizationSite::factory()->in($this->world->lgas['A1a'])->create(['name' => 'Export Terminal', 'security_relevance' => SecurityRelevance::CriticalInfrastructure]);
    OrganizationSite::factory()->in($this->world->lgas['A1b'])->create(['name' => 'Market Hall', 'security_relevance' => SecurityRelevance::High]);
    OrganizationSite::factory()->in($this->world->lgas['A1b'])->create(['name' => 'Corner Shop', 'security_relevance' => SecurityRelevance::Low]);

    $groups = CrossFixtures::signIn($this->sd)->get("/states/{$this->a1->ulid}?tab=organizations")->viewData('page')['props']['organizations'];

    expect(collect($groups)->flatMap(fn ($g) => collect($g['sites'])->pluck('name'))->sort()->values()->all())->toBe(['Export Terminal', 'Market Hall']);
});

test('the incidents tab summarises the last 90 days by type and LGA through the viewer\'s scopes', function () {
    $kidnap = IncidentType::factory()->create(['name' => 'Kidnapping']);
    $robbery = IncidentType::factory()->create(['name' => 'Armed robbery']);
    $author = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $kidnap->id, 'occurred_at' => now()->subDays(10)]), IncidentStatus::Verified);
    W::at(W::draft($this->world->lgas['A1b'], $author, ['incident_type_id' => $kidnap->id, 'occurred_at' => now()->subDays(20)]), IncidentStatus::Verified);
    W::at(W::draft($this->world->lgas['A1b'], $author, ['incident_type_id' => $robbery->id, 'occurred_at' => now()->subDays(20), 'classification' => Classification::Secret]), IncidentStatus::Verified);
    W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $robbery->id, 'occurred_at' => now()->subDays(120)]), IncidentStatus::Verified);
    W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $robbery->id, 'occurred_at' => now()->subDays(5)]), IncidentStatus::Invalid);
    W::at(W::draft($this->world->lgas['A2'], CrossFixtures::chiefOf($this->world->lgas['A2']), ['incident_type_id' => $kidnap->id, 'occurred_at' => now()->subDays(5)]), IncidentStatus::Verified);

    $summary = fn (User $user) => CrossFixtures::signIn($user)->get("/states/{$this->a1->ulid}?tab=incidents")->viewData('page')['props']['incidents'];

    expect($summary($this->sd)['byType'])->toBe([['type' => 'Kidnapping', 'count' => 2], ['type' => 'Armed robbery', 'count' => 1]])
        ->and($summary($this->sd)['total'])->toBe(3)
        ->and($summary($this->dsd)['total'])->toBe(2)
        ->and(collect($summary($this->sd)['byLga'])->pluck('count', 'lga.name')->all())->toBe([$this->world->lgas['A1b']->name => 2, $this->world->lgas['A1a']->name => 1]);
});

test('the history tab shows the profile\'s and the state\'s audit entries', function () {
    CrossFixtures::signIn($this->sd)->put("/states/{$this->a1->ulid}/profile", ['terrain' => ['urban'], 'capital' => 'Ikeja'])->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->sd)->get("/states/{$this->a1->ulid}?tab=history")->assertInertia(fn (Assert $page) => $page
        ->where('history.total', fn ($total) => $total >= 2));
});

test('the national state grid and every page share the openable states for links', function () {
    CrossFixtures::signIn($this->chief)->get("/states/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('stateLinks', [$this->a1->ulid]));
    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->get('/states')->assertInertia(fn (Assert $page) => $page
        ->where('stateLinks', fn ($links) => collect($links)->sort()->values()->all() === collect([$this->a1->ulid, $this->world->states['A2']->ulid])->sort()->values()->all()));
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('stateLinks', []));
});
