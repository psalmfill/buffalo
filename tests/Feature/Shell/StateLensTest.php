<?php

use App\Enums\IncidentStatus;
use App\Enums\ThreatUrgency;
use App\Models\Incident;
use App\Models\User;
use App\Support\Dashboard\StateGrid;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Lens\StateLens;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 11A.2: the state grid, state and zone dashboards for national
| users, and the state lens. The world: zone A (states A1, A2), zone B
| (state B1); a DG, a DDG for zone A, a State Director of A1.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'Africa/Lagos'));
    $this->world = JurisdictionWorld::build();
    $this->dg = $this->world->users['director_general'];
    $this->ddgA = $this->world->users['ddg_zone_a'];
    $this->sd = $this->world->users['state_director'];
    $this->chief = $this->world->users['lga_chief'];
    [$this->a1, $this->a2, $this->b1] = [$this->world->states['A1'], $this->world->states['A2'], $this->world->states['B1']];

    $make = fn (string $lga, IncidentStatus $status = IncidentStatus::Verified, array $attributes = [], array $columns = []) => W::at(
        W::draft($this->world->lgas[$lga], $this->chief, ['occurred_at' => CarbonImmutable::now()->subDays(2), ...$attributes]), $status, $columns,
    );

    // B1 needs the most attention: two unacknowledged escalations and a
    // critical open incident. A1: one critical incident. A2: quiet.
    $this->b1Incidents = [
        $make('B1', IncidentStatus::Verified, ['severity' => 'critical', 'title' => 'Bandit attack on Kawo market'], ['escalated_at' => now()]),
        $make('B1', IncidentStatus::Submitted, [], ['escalated_at' => now()]),
    ];
    $this->a1Incidents = [
        $make('A1a', IncidentStatus::UnderInvestigation, ['severity' => 'critical', 'title' => 'Bandit attack on Oja market']),
        $make('A1b', IncidentStatus::Verified),
    ];
    // Never counted (statistics rule).
    $make('A1a', IncidentStatus::Draft);
    $make('A1a', IncidentStatus::Invalid);
    T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate]);
});

function lensSession(string $type, string $ulid): array
{
    return [StateLens::SESSION_KEY => ['type' => $type, 'ulid' => $ulid]];
}

test('the national dashboard shows a state grid of states within reach, most urgent first', function () {
    CrossFixtures::signIn($this->dg)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/national')
        ->where('stateGrid', fn ($cards) => collect($cards)->pluck('state.ulid')->all() === [$this->b1->ulid, $this->a1->ulid, $this->a2->ulid])
        ->where('stateGrid.0.escalationsUnacknowledged', 2)
        ->where('stateGrid.0.criticalOpen', 1)
        ->where('stateGrid.1.immediateThreats', 1)
        // Drafts and invalid reports never count.
        ->where('stateGrid.1.incidents7', 2)
        ->where('stateGrid.2.attention.level', 'none'));

    // A zoned DDG sees their zone's states only.
    CrossFixtures::signIn($this->ddgA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('stateGrid', fn ($cards) => collect($cards)->pluck('state.ulid')->sort()->values()->all() === collect([$this->a1->ulid, $this->a2->ulid])->sort()->values()->all()));
});

test('the attention score is the configured weighted sum', function () {
    config(['buffalo.dashboard.attention_weights' => ['criticalOpen' => 4, 'escalationsUnacknowledged' => 5]]);

    expect(StateGrid::score(['criticalOpen' => 1, 'escalationsUnacknowledged' => 2, 'incidents7' => 10], (array) config('buffalo.dashboard.attention_weights')))->toBe(14.0)
        ->and(StateGrid::level(25))->toBe('critical')
        ->and(StateGrid::level(0))->toBe('none');

    // Weights change the order: counting only immediate threats puts A1 first.
    config(['buffalo.dashboard.attention_weights' => ['immediateThreats' => 1]]);
    CrossFixtures::signIn($this->dg)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('stateGrid.0.state.ulid', $this->a1->ulid));
});

test('state grid figures equal the state dashboard figures', function () {
    $grid = collect(CrossFixtures::signIn($this->dg)->get('/dashboard')->viewData('page')['props']['stateGrid'])->keyBy('state.ulid');

    foreach ([$this->a1, $this->b1] as $state) {
        $kpis = collect(CrossFixtures::signIn($this->dg)->get("/dashboard/states/{$state->ulid}")->viewData('page')['props']['kpis'])->keyBy('key');

        expect($grid[$state->ulid]['incidents30'])->toBe($kpis['incidents']['value'])
            ->and($grid[$state->ulid]['killed30'])->toBe($kpis['killed']['value'])
            ->and($grid[$state->ulid]['abducted30'])->toBe($kpis['abducted']['value'])
            ->and($grid[$state->ulid]['immediateThreats'])->toBe($kpis['immediate_threats']['value']);
    }

    // The same figures as the State Director's own dashboard.
    $own = collect(CrossFixtures::signIn($this->sd)->get('/dashboard')->viewData('page')['props']['kpis'])->keyBy('key');
    expect($grid[$this->a1->ulid]['incidents30'])->toBe($own['incidents']['value']);
});

test('a national user sees the same state dashboard a State Director sees, only for states within reach', function () {
    $own = CrossFixtures::signIn($this->sd)->get('/dashboard')->viewData('page')['props'];

    CrossFixtures::signIn($this->dg)->get("/dashboard/states/{$this->a1->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/state')
        ->where('state.ulid', $this->a1->ulid)
        ->where('place.type', 'state')
        ->where('lgas', fn ($lgas) => collect($lgas)->pluck('ulid')->sort()->values()->all() === collect($own['lgas'])->pluck('ulid')->sort()->values()->all())
        // Incident figures are narrowed to A1: never B1's escalations.
        ->where('incidents.escalated', fn ($rows) => collect($rows)->isEmpty()));

    CrossFixtures::signIn($this->ddgA)->get("/dashboard/states/{$this->a1->ulid}")->assertOk();
    CrossFixtures::signIn($this->ddgA)->get("/dashboard/states/{$this->b1->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->sd)->get("/dashboard/states/{$this->a1->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->world->users['super_admin'])->get("/dashboard/states/{$this->a1->ulid}")->assertForbidden();
});

test('a zone dashboard covers the zone\'s states', function () {
    $zoneA = $this->world->zones['A'];

    CrossFixtures::signIn($this->dg)->get("/dashboard/zones/{$zoneA->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/national')
        ->where('place.type', 'zone')
        ->where('scope.states', 2)
        ->where('stateGrid', fn ($cards) => ! collect($cards)->pluck('state.ulid')->contains($this->b1->ulid)));

    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->get("/dashboard/zones/{$zoneA->ulid}")->assertForbidden();
});

test('the lens can only be set to states or zones within reach, and state users have none', function () {
    CrossFixtures::signIn($this->dg)->put('/lens', ['type' => 'state', 'ulid' => $this->b1->ulid])
        ->assertRedirect("/dashboard/states/{$this->b1->ulid}")
        ->assertSessionHas(StateLens::SESSION_KEY.'.ulid', $this->b1->ulid);

    // A zoned DDG cannot choose an outside state or zone.
    CrossFixtures::signIn($this->ddgA)->put('/lens', ['type' => 'state', 'ulid' => $this->b1->ulid])->assertForbidden();
    CrossFixtures::signIn($this->ddgA)->put('/lens', ['type' => 'zone', 'ulid' => $this->world->zones['B']->ulid])->assertForbidden();
    CrossFixtures::signIn($this->ddgA)->put('/lens', ['type' => 'zone', 'ulid' => $this->world->zones['A']->ulid])->assertRedirect();

    // State and LGA users: no lens control and no lens.
    CrossFixtures::signIn($this->sd)->put('/lens', ['type' => 'state', 'ulid' => $this->a1->ulid])->assertForbidden();
    CrossFixtures::signIn($this->sd)->delete('/lens')->assertForbidden();
    CrossFixtures::signIn($this->sd)->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page->where('lens', null));

    // The picker offers the zoned DDG only their states.
    CrossFixtures::signIn($this->ddgA)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->where('lens.states', fn ($states) => collect($states)->pluck('ulid')->sort()->values()->all() === collect([$this->a1->ulid, $this->a2->ulid])->sort()->values()->all())
        ->where('lens.zones', fn ($zones) => collect($zones)->pluck('ulid')->all() === [$this->world->zones['A']->ulid]));
});

test('the lens never changes scopes', function () {
    // A lens outside reach (e.g. a stale session) is ignored...
    CrossFixtures::signIn($this->ddgA)->withSession(lensSession('state', $this->b1->ulid))->get('/incidents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('lens.current', null));

    // ...and an explicit filter for an outside state shows nothing.
    CrossFixtures::signIn($this->ddgA)->get("/incidents?state={$this->b1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data', fn ($rows) => collect($rows)->isEmpty()));

    // The focus only narrows: a zoned DDG focused on B1 still sees nothing there.
    $ids = app(JurisdictionContext::class)->actingAs($this->ddgA, fn () => app(JurisdictionContext::class)->focus([$this->b1->id], fn () => Incident::query()->pluck('id')->all()));
    expect($ids)->toBe([]);

    // Nested focuses intersect.
    $ids = app(JurisdictionContext::class)->actingAs($this->dg, fn () => app(JurisdictionContext::class)->focus([$this->a1->id, $this->b1->id], fn () => app(JurisdictionContext::class)->focus([$this->b1->id], fn () => Incident::query()->pluck('state_id')->unique()->values()->all())));
    expect($ids)->toBe([$this->b1->id]);
});

test('lists, the incident map and analytics open on the lens; URL filters override it', function () {
    $dg = fn () => CrossFixtures::signIn($this->dg)->withSession(lensSession('state', $this->a1->ulid));

    foreach (['/incidents', '/incidents/map', '/threats', '/flashpoints', '/persons', '/persons-of-interest', '/coverage-reports', '/formations', '/organizations', '/analytics'] as $list) {
        $dg()->get($list)->assertRedirect("{$list}?state={$this->a1->ulid}");
    }

    $dg()->get("/incidents?state={$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data', fn ($rows) => collect($rows)->pluck('ulid')->sort()->values()->all() === collect($this->a1Incidents)->pluck('ulid')->sort()->values()->all()));

    // An explicit filter wins; lens=off (a cleared filter) shows everything.
    $dg()->get("/incidents?state={$this->b1->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('incidents.data', fn ($rows) => collect($rows)->pluck('ulid')->sort()->values()->all() === collect($this->b1Incidents)->pluck('ulid')->sort()->values()->all()));
    $dg()->get('/incidents?lens=off')->assertOk()->assertInertia(fn (Assert $page) => $page->where('incidents.total', 4));

    // A zone lens sets the zone filter, which narrows to the zone's states.
    $zone = fn () => CrossFixtures::signIn($this->dg)->withSession(lensSession('zone', $this->world->zones['B']->ulid));
    $zone()->get('/threats')->assertRedirect("/threats?zone={$this->world->zones['B']->ulid}");
    $zone()->get("/incidents?zone={$this->world->zones['B']->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('incidents.total', 2)
        ->where('filterOptions.zones', fn ($zones) => count($zones) === 2)); // zone E has no states
    $zone()->get("/threats?zone={$this->world->zones['B']->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('threats.total', 0)
        ->where('filters.zone', $this->world->zones['B']->ulid));

    // Analytics default their geography to the lens.
    $dg()->get("/analytics?state={$this->a1->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.state.ulid', $this->a1->ulid));

    // Dashboards open on the lens; lens=off is the national view.
    $dg()->get('/dashboard')->assertRedirect("/dashboard/states/{$this->a1->ulid}");
    $dg()->get('/dashboard?lens=off')->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard/national'));

    // Clearing the lens returns to national.
    $dg()->delete('/lens')->assertRedirect('/dashboard')->assertSessionMissing(StateLens::SESSION_KEY);
});

test('the operational map opens on the lens state', function () {
    DB::table('states')->where('id', $this->a1->id)->update(['centroid' => DB::raw("ST_GeogFromText('SRID=4326;POINT(3.4 6.5)')")]);

    CrossFixtures::signIn($this->dg)->withSession(lensSession('state', $this->a1->ulid))->get('/map')->assertInertia(fn (Assert $page) => $page
        ->where('view.center', [6.5, 3.4])
        ->where('view.zoom', 8));
    CrossFixtures::signIn($this->dg)->withSession(lensSession('zone', $this->world->zones['A']->ulid))->get('/map')->assertInertia(fn (Assert $page) => $page
        ->where('view.zoom', 7));
    CrossFixtures::signIn($this->dg)->get('/map')->assertInertia(fn (Assert $page) => $page->where('view.center', null));
});

test('global search ranks results in the lens state first and still returns others', function () {
    $search = fn (User $user, array $session = []) => collect(CrossFixtures::signIn($user)->withSession($session)->getJson('/search/quick?q=bandit+attack+market')->json('sections'))
        ->firstWhere('key', 'incidents')['hits'] ?? [];

    $b1 = $this->b1Incidents[0]->ulid;
    $a1 = $this->a1Incidents[0]->ulid;

    expect(collect($search($this->dg, lensSession('state', $this->b1->ulid)))->pluck('ulid')->all())->toBe([$b1, $a1])
        ->and(collect($search($this->dg, lensSession('state', $this->a1->ulid)))->pluck('ulid')->all())->toBe([$a1, $b1]);
});
