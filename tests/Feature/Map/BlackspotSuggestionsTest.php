<?php

use App\Actions\Blackspots\GenerateBlackspotSuggestions;
use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Models\BlackspotSuggestion;
use App\Models\Flashpoint;
use App\Models\IncidentType;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\BlackspotSuggestionsReady;
use App\Support\Jurisdiction\BlackspotClustering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->type = IncidentType::factory()->create(['name' => 'Kidnapping', 'category' => IncidentCategory::Crime]);
    $this->threat = ThreatType::factory()->create(['name' => 'Kidnapping threat', 'category' => IncidentCategory::Crime]);
    $this->state = $this->world->states['A1'];
});

/**
 * A tight cluster of $n incidents (within about 150 m of 6.50 N, 3.40 E)
 * in A1a, $secret of them secret, and diffuse incidents spread widely.
 */
function cluster(JurisdictionWorld $world, IncidentType $type, int $n = 8, int $secret = 0): array
{
    $ids = [];
    foreach (range(1, $n) as $i) {
        $incident = W::draft($world->lgas['A1a'], $i <= $secret ? $world->users['state_director'] : $world->users['lga_chief'], [
            'incident_type_id' => $type->id,
            'location' => Flashpoint::pointFrom(6.50 + ($i % 3) * 0.0005, 3.40 + intdiv($i, 3) * 0.0005),
            'occurred_at' => CarbonImmutable::now()->subDays(10 + $i),
            'classification' => $i <= $secret ? Classification::Secret : Classification::Restricted,
        ]);
        $ids[] = W::at($incident, IncidentStatus::Verified)->id;
    }

    // Diffuse background: one incident every ~11 km.
    foreach (range(0, 3) as $x) {
        foreach (range(0, 3) as $y) {
            W::at(W::draft($world->lgas['A1b'], $world->users['state_director'], [
                'incident_type_id' => $type->id,
                'location' => Flashpoint::pointFrom(6.0 + $y * 0.1, 3.0 + $x * 0.1),
                'occurred_at' => CarbonImmutable::now()->subDays(20),
            ]), IncidentStatus::Verified);
        }
    }

    // Never counted.
    W::draft($world->lgas['A1a'], $world->users['lga_chief'], ['incident_type_id' => $type->id, 'location' => Flashpoint::pointFrom(6.5, 3.4)]);

    return $ids;
}

function generate(int $stateId): array
{
    CrossFixtures::system();

    return app(GenerateBlackspotSuggestions::class)($stateId);
}

test('DBSCAN finds the tight cluster and ignores diffuse incidents and drafts', function () {
    $ids = cluster($this->world, $this->type);

    expect(generate($this->state->id))->toBe(['new' => 1, 'updated' => 0, 'removed' => 0]);

    $suggestion = BlackspotSuggestion::query()->withCoordinates()->sole();
    expect($suggestion->incident_count)->toBe(8)
        ->and($suggestion->lga_id)->toBe($this->world->lgas['A1a']->id)
        ->and($suggestion->radius_meters)->toBe(500)
        ->and($suggestion->dominant_incident_type_id)->toBe($this->type->id)
        ->and(round($suggestion->latitude, 2))->toBe(6.5)
        ->and(DB::table('blackspot_suggestion_incidents')->where('blackspot_suggestion_id', $suggestion->id)->pluck('incident_id')->sort()->values()->all())->toBe($ids);

    // Running again updates in place (same ULID), nothing new.
    $ulid = $suggestion->ulid;
    expect(generate($this->state->id))->toBe(['new' => 0, 'updated' => 1, 'removed' => 0])
        ->and(BlackspotSuggestion::query()->sole()->ulid)->toBe($ulid);
});

test('the parameters come from config', function () {
    cluster($this->world, $this->type, 4);
    expect(generate($this->state->id)['new'])->toBe(0);

    config(['buffalo.blackspots.min_points' => 4]);
    expect(generate($this->state->id)['new'])->toBe(1);

    // eps too small to connect the members: nothing.
    config(['buffalo.blackspots.eps_meters' => 10]);
    expect(generate($this->state->id))->toMatchArray(['new' => 0, 'removed' => 1]);
});

test('chance clusters in diffuse activity are not suggested', function () {
    expect(BlackspotClustering::poissonUpperTail(10, 2.8))->toBeGreaterThan(1e-4)
        ->and(BlackspotClustering::poissonUpperTail(15, 2.8))->toBeLessThan(1e-5)
        ->and(BlackspotClustering::poissonUpperTail(0, 3.0))->toBe(1.0);
});

test('areas covered by an active or monitoring flashpoint are skipped', function () {
    cluster($this->world, $this->type);
    generate($this->state->id);

    Flashpoint::factory()->in($this->world->lgas['A1a'])->at(6.5005, 3.4005, 1000)->create(['status' => 'dormant']);
    expect(generate($this->state->id)['updated'])->toBe(1);

    Flashpoint::factory()->in($this->world->lgas['A1a'])->at(6.5005, 3.4005, 1000)->create(['status' => 'monitoring']);
    expect(generate($this->state->id))->toBe(['new' => 0, 'updated' => 0, 'removed' => 1]);
});

test('dismissals need a reason and hold for 90 days', function () {
    cluster($this->world, $this->type);
    generate($this->state->id);
    $suggestion = BlackspotSuggestion::query()->sole();

    CrossFixtures::signIn($this->world->users['lga_chief'])->post("/blackspot-suggestions/{$suggestion->ulid}/dismiss", ['reason' => 'x'])->assertForbidden();
    CrossFixtures::signIn($this->dsd)->post("/blackspot-suggestions/{$suggestion->ulid}/dismiss", [])->assertSessionHasErrors('reason');
    CrossFixtures::signIn($this->dsd)->post("/blackspot-suggestions/{$suggestion->ulid}/dismiss", ['reason' => 'A police post is already there'])->assertSessionHasNoErrors();

    expect(DB::table('blackspot_suggestions')->where('id', $suggestion->id)->value('status'))->toBe('dismissed')
        ->and(generate($this->state->id)['new'])->toBe(0);

    $this->travel(91)->days();
    expect(generate($this->state->id)['new'])->toBe(1);
});

test('counts and members follow the viewer\'s scopes; a suggestion is hidden below min_points visible incidents', function () {
    cluster($this->world, $this->type, 8, secret: 3);
    generate($this->state->id);

    CrossFixtures::signIn($this->sd)->get('/blackspot-suggestions')->assertInertia(fn (Assert $page) => $page->where('suggestions.0.incident_count', 8));
    CrossFixtures::signIn($this->dsd)->get('/blackspot-suggestions')->assertInertia(fn (Assert $page) => $page
        ->where('suggestions.0.incident_count', 5)
        ->missing('suggestions.0.id'));

    $ulid = BlackspotSuggestion::query()->value('ulid');
    CrossFixtures::signIn($this->dsd)->get("/blackspot-suggestions?open={$ulid}")->assertInertia(fn (Assert $page) => $page->has('open.members', 5));

    // With four secret members the deputy sees four: below min_points, hidden.
    DB::table('incidents')->whereIn('id', DB::table('blackspot_suggestion_incidents')->select('incident_id'))->where('classification', 'restricted')->limit(1)
        ->update(['classification' => 'secret', 'created_by' => $this->sd->id]);
    CrossFixtures::signIn($this->dsd)->get('/blackspot-suggestions')->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
    CrossFixtures::signIn($this->dsd)->getJson('/map/layers/suggestions?bbox=3,6,4,7&zoom=10')->assertJsonCount(0, 'features');
    CrossFixtures::signIn($this->sd)->getJson('/map/layers/suggestions?bbox=3,6,4,7&zoom=10')->assertJsonPath('features.0.properties.incident_count', 8);

    // LGA chiefs have no access; other states see nothing.
    CrossFixtures::signIn($this->world->users['lga_chief'])->get('/blackspot-suggestions')->assertForbidden();
    CrossFixtures::signIn(User::factory()->stateDirector($this->world->states['B1'])->create())->get('/blackspot-suggestions')->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
});

test('accepting pre-fills a blackspot and links the visible incidents', function () {
    $ids = cluster($this->world, $this->type);
    generate($this->state->id);
    $suggestion = BlackspotSuggestion::query()->sole();

    CrossFixtures::signIn($this->sd)->get("/flashpoints/create?suggestion={$suggestion->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('suggestion.ulid', $suggestion->ulid)
        ->where('suggestion.radius_meters', 500)
        ->where('suggestion.threat_type', $this->threat->ulid)
        ->where('lga.ulid', $this->world->lgas['A1a']->ulid));

    CrossFixtures::signIn($this->sd)->post('/flashpoints', [
        'lga' => $this->world->lgas['A1a']->ulid,
        'kind' => 'blackspot',
        'name' => 'Toll gate cluster',
        'threat_type' => $this->threat->ulid,
        'latitude' => 6.5005,
        'longitude' => 3.4005,
        'radius_meters' => 500,
        'description' => 'Repeated kidnappings at the toll gate.',
        'classification' => 'restricted',
        'concern_level' => 'high',
        'status' => 'active',
        'assessment' => 'Cluster of eight kidnappings.',
        'suggestion' => $suggestion->ulid,
    ])->assertSessionHasNoErrors();

    CrossFixtures::system();
    $flashpoint = Flashpoint::query()->sole();
    expect(DB::table('flashpoint_incidents')->where('flashpoint_id', $flashpoint->id)->pluck('incident_id')->sort()->values()->all())->toBe($ids)
        ->and(DB::table('blackspot_suggestions')->where('id', $suggestion->id)->value('status'))->toBe('accepted')
        ->and(DB::table('blackspot_suggestions')->where('id', $suggestion->id)->value('flashpoint_id'))->toBe($flashpoint->id);

    // A decided suggestion cannot be accepted again; the area is now covered.
    CrossFixtures::signIn($this->sd)->get("/flashpoints/create?suggestion={$suggestion->ulid}")->assertForbidden();
    expect(generate($this->state->id)['new'])->toBe(0);
});

test('the nightly job notifies the State Director of new suggestions; refresh is SD and above', function () {
    cluster($this->world, $this->type);

    $this->artisan('buffalo:suggest-blackspots')->assertSuccessful();
    Notification::assertSentTo($this->sd, BlackspotSuggestionsReady::class, fn (BlackspotSuggestionsReady $n) => $n->count === 1 && ! str_contains($n->body($this->sd), 'Kidnapping'));
    Notification::assertNotSentTo($this->dsd, BlackspotSuggestionsReady::class);

    CrossFixtures::signIn($this->dsd)->post('/blackspot-suggestions/refresh')->assertForbidden();
    CrossFixtures::signIn($this->sd)->post('/blackspot-suggestions/refresh')->assertSessionHasNoErrors()->assertSessionHas('toast');
});
