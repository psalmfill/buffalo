<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Geo\BoundaryImporter;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Map\LayerCache;
use App\Support\Map\Tile;
use App\Support\Map\ZoomBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

const VIEW = 'bbox=3.0,6.0,4.0,7.0&zoom=12';

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
});

/** An incident at a point, in a status, with a classification. */
function mapIncident(JurisdictionWorld $world, string $lga, float $lat, float $lng, IncidentStatus $status = IncidentStatus::Verified, array $attributes = [], ?User $by = null): Incident
{
    $incident = W::draft($world->lgas[$lga], $by ?? $world->users['lga_chief'], [
        'location' => Flashpoint::pointFrom($lat, $lng),
        'occurred_at' => CarbonImmutable::now()->subDays(5),
        ...$attributes,
    ]);

    return $status === IncidentStatus::Draft ? $incident : W::at($incident, $status);
}

/** A mix of incidents: visible to different roles, drafts and invalid ones. */
function mapFixture(JurisdictionWorld $world): void
{
    foreach (range(0, 5) as $i) {
        mapIncident($world, 'A1a', 6.50 + $i * 0.001, 3.40);
    }
    mapIncident($world, 'A1a', 6.60, 3.50, IncidentStatus::Closed);
    mapIncident($world, 'A1a', 6.60, 3.50, IncidentStatus::Draft);
    mapIncident($world, 'A1a', 6.60, 3.50, IncidentStatus::Invalid);
    mapIncident($world, 'A1a', 6.51, 3.41, attributes: ['classification' => Classification::Secret], by: $world->users['state_director']);
    mapIncident($world, 'A1a', 6.51, 3.41, attributes: ['classification' => Classification::Confidential], by: $world->users['state_director']);
    mapIncident($world, 'A1b', 6.70, 3.60);
    mapIncident($world, 'B1', 6.80, 3.70);
    mapIncident($world, 'A1a', 6.50, 3.40, attributes: ['occurred_at' => CarbonImmutable::now()->subDays(200)]);
}

/** Counted incidents with a location the user can see, as the list would count them. */
function scopedCount(User $user, array $filters = []): int
{
    return app(JurisdictionContext::class)->actingAs($user, fn () => IncidentFilters::from([
        'statuses' => ['submitted', 'returned', 'verified', 'under_investigation', 'resolved', 'closed'],
        'from' => CarbonImmutable::now()->subDays(90)->toDateString(),
        ...$filters,
    ])->apply(Incident::query(), $user, sort: false)->whereNotNull('incidents.location')->count());
}

function layer(User $user, string $path): array
{
    return CrossFixtures::signIn($user)->getJson($path)->assertOk()->json();
}

test('density counts equal each viewer\'s scoped incidents; drafts, invalid and old ones excluded', function (string $who, int $expected) {
    mapFixture($this->world);
    $user = $this->world->users[$who];

    $data = layer($user, '/map/layers/density?'.VIEW);
    $total = array_sum(array_column(array_column($data['features'], 'properties'), 'count'));

    expect($total)->toBe($expected)
        ->and($total)->toBe(scopedCount($user))
        ->and(collect($data['features'])->every(fn ($f) => $f['properties']['count'] > 0))->toBeTrue()
        ->and($data['meta']['hex_meters'])->toBe(2000);
})->with([
    // 6 verified + 1 closed in A1a (the classified ones are the SD's).
    ['lga_chief', 7],
    // State A1: the deputy cannot see the secret one.
    ['deputy_state_director', 9],
    ['state_director', 10],
    // Plus B1.
    ['director_general', 11],
]);

test('filters narrow the incident layers', function () {
    mapFixture($this->world);
    mapIncident($this->world, 'A1a', 6.55, 3.45, IncidentStatus::Closed, ['severity' => Severity::Critical]);

    $data = layer($this->sd, '/map/layers/density?'.VIEW.'&severities[]=critical');
    $closed = layer($this->sd, '/map/layers/density?'.VIEW.'&statuses[]=closed');
    // Drafts and invalid reports cannot be asked for.
    $sneaky = layer($this->sd, '/map/layers/density?'.VIEW.'&statuses[]=draft&statuses[]=invalid');

    expect(array_sum(array_column(array_column($data['features'], 'properties'), 'count')))->toBe(1)
        ->and(array_sum(array_column(array_column($closed['features'], 'properties'), 'count')))->toBe(2)
        ->and(array_sum(array_column(array_column($sneaky['features'], 'properties'), 'count')))->toBe(scopedCount($this->sd));
});

test('hexagon size follows the zoom bucket', function (int $zoom, int $size) {
    expect(ZoomBucket::forZoom($zoom)->hexMeters)->toBe($size);
    mapFixture($this->world);
    expect(layer($this->sd, "/map/layers/density?bbox=3.0,6.0,4.0,7.0&zoom={$zoom}")['meta']['hex_meters'])->toBe($size);
})->with([[5, 50000], [8, 20000], [10, 5000], [12, 2000], [16, 500]]);

test('a hexagon\'s incident list holds exactly the incidents it counts', function () {
    mapFixture($this->world);

    foreach ([12 => '3.0,6.0,4.0,7.0', 16 => '3.35,6.45,3.55,6.65'] as $zoom => $bbox) {
        $data = layer($this->dsd, "/map/layers/density?bbox={$bbox}&zoom={$zoom}");
        expect($data['features'])->not->toBeEmpty();

        foreach ($data['features'] as $feature) {
            expect(scopedCount($this->dsd, ['hex' => $feature['properties']['hex']]))->toBe($feature['properties']['count']);
        }
    }

    // Over HTTP: the list page counts the same.
    $feature = layer($this->dsd, '/map/layers/density?'.VIEW)['features'][0];
    CrossFixtures::signIn($this->dsd)->get('/incidents?hex='.$feature['properties']['hex'].'&from='.CarbonImmutable::now()->subDays(90)->toDateString().'&statuses[]=verified&statuses[]=closed&statuses[]=submitted&statuses[]=returned&statuses[]=under_investigation&statuses[]=resolved')
        ->assertInertia(fn (Assert $page) => $page->where('incidents.total', $feature['properties']['count']));

    // The bounding-box filter lists the incidents inside a box.
    expect(scopedCount($this->sd, ['bbox' => '3.39,6.49,3.42,6.52']))->toBe(8);
});

test('tiles are snapped to a fixed grid so that panning reuses them', function () {
    $bucket = ZoomBucket::forZoom(10);
    $a = array_map(fn (Tile $t) => $t->key(), Tile::covering([3.1, 6.1, 3.9, 6.9], $bucket));
    $b = array_map(fn (Tile $t) => $t->key(), Tile::covering([3.15, 6.12, 3.95, 6.95], $bucket));

    expect($a)->toBe($b)
        ->and(Tile::covering([3.1, 6.1, 3.9, 6.9], $bucket)[0]->bounds())->toBe([3.0, 6.0, 4.0, 7.0])
        // Too many tiles: the client must zoom in.
        ->and(Tile::covering([0, 0, 15, 15], ZoomBucket::forZoom(16)))->toBeNull();
});

test('cached layers are never shared between scope signatures and are refreshed by incident events', function () {
    config(['cache.default' => 'array']);
    mapFixture($this->world);

    $sum = fn (User $u) => array_sum(array_column(array_column(layer($u, '/map/layers/density?'.VIEW)['features'], 'properties'), 'count'));

    // Warm the SD's cache, then the deputy (same state, lower clearance) gets their own.
    expect($sum($this->sd))->toBe(10)
        ->and($sum($this->dsd))->toBe(9)
        ->and($sum($this->sd))->toBe(10);

    // A new incident submitted in the state: cached results are dropped.
    $new = mapIncident($this->world, 'A1a', 6.52, 3.42, IncidentStatus::Draft);
    W::submit($this->chief, $new)->assertSessionHasNoErrors();

    expect($sum($this->sd))->toBe(11)
        ->and($sum($this->dsd))->toBe(10);
});

test('the LGA choropleth counts per LGA, with rates, and falls back to centroids or a notice', function () {
    mapFixture($this->world);
    $url = '/map/layers/lga-choropleth?bbox=0,2,17,16&zoom=8';

    // No boundaries or centroids: unavailable.
    $data = layer($this->sd, $url);
    expect($data['meta']['mode'])->toBe('unavailable')
        ->and($data['meta']['notice'])->toBe('LGA boundaries have not been imported.');

    // Centroids only: graduated circles.
    foreach (['A1a' => [6.5, 3.4], 'A1b' => [6.7, 3.6]] as $key => [$lat, $lng]) {
        DB::statement('UPDATE lgas SET centroid = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, population_estimate = 200000 WHERE id = ?', [$lng, $lat, $this->world->lgas[$key]->id]);
    }
    // Set directly in the test (an import bumps the cache versions itself).
    LayerCache::bumpAll();
    $data = layer($this->sd, $url);
    $counts = collect($data['features'])->mapWithKeys(fn ($f) => [$f['properties']['name'] => $f['properties']['count']]);
    expect($data['meta']['mode'])->toBe('centroids')
        ->and($data['features'][0]['geometry']['type'])->toBe('Point')
        ->and($counts[$this->world->lgas['A1a']->name])->toBe(scopedCount($this->sd, ['lga' => $this->world->lgas['A1a']->ulid]))
        ->and($counts[$this->world->lgas['A1b']->name])->toBe(1);

    // With boundaries: polygons; rates per 100,000.
    DB::statement("UPDATE lgas SET boundary = ST_GeogFromText('SRID=4326;MULTIPOLYGON(((3.3 6.4, 3.5 6.4, 3.5 6.6, 3.3 6.6, 3.3 6.4)))') WHERE id = ?", [$this->world->lgas['A1a']->id]);
    app(BoundaryImporter::class)->simplify();
    $data = layer($this->sd, $url.'&measure=rate');
    $a1a = collect($data['features'])->firstWhere('properties.name', $this->world->lgas['A1a']->name);
    expect($data['meta']['mode'])->toBe('polygons')
        ->and($a1a['geometry']['type'])->toBe('MultiPolygon')
        ->and($a1a['properties']['value'])->toBe(round($a1a['properties']['count'] * 100000 / 200000, 1));

    // The chief's choropleth covers only their LGA.
    expect(collect(layer($this->chief, $url)['features'])->pluck('properties.name')->all())->toBe([$this->world->lgas['A1a']->name]);
});

test('the state choropleth is for national users only', function () {
    mapFixture($this->world);

    expect(layer($this->sd, '/map/layers/state-choropleth?bbox=0,2,17,16&zoom=6')['meta']['mode'])->toBe('unavailable');

    DB::statement('UPDATE states SET centroid = ST_SetSRID(ST_MakePoint(3.4, 6.5), 4326)::geography WHERE id = ?', [$this->world->states['A1']->id]);
    $data = layer($this->world->users['director_general'], '/map/layers/state-choropleth?bbox=0,2,17,16&zoom=6');
    expect($data['meta']['mode'])->toBe('centroids')
        ->and($data['features'][0]['properties']['count'])->toBe(10);
});

test('layers need map.view and the module\'s permission; the page lists only permitted layers', function () {
    CrossFixtures::signIn($this->world->users['super_admin'])->getJson('/map/layers/density?'.VIEW)->assertForbidden();
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/map')->assertForbidden();
    CrossFixtures::signIn($this->chief)->getJson('/map/layers/suggestions?'.VIEW)->assertForbidden();
    CrossFixtures::signIn($this->chief)->getJson('/map/layers/density?bbox=bad&zoom=8')->assertUnprocessable();

    CrossFixtures::signIn($this->chief)->get('/map')->assertInertia(fn (Assert $page) => $page
        ->component('map/index')
        ->where('layers', ['density', 'lga_choropleth', 'incidents', 'flashpoints', 'threats', 'formations', 'sites', 'communities'])
        ->where('boundaries.lga', 'none'));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/map')->assertInertia(fn (Assert $page) => $page
        ->where('layers', ['density', 'lga_choropleth', 'state_choropleth', 'incidents', 'flashpoints', 'threats', 'suggestions', 'formations', 'sites', 'communities']));
});

test('point layers return what the viewer may see; LGA-wide threats are drawn as the LGA outline', function () {
    Flashpoint::factory()->in($this->world->lgas['A1a'])->at(6.5, 3.4)->create(['name' => 'Visible']);
    Flashpoint::factory()->in($this->world->lgas['B1'])->at(6.5, 3.4)->create(['name' => 'Other zone']);
    Flashpoint::factory()->in($this->world->lgas['A1a'])->at(6.5, 3.4)->classified(Classification::Secret)->create(['name' => 'Secret']);

    expect(collect(layer($this->chief, '/map/layers/flashpoints?'.VIEW)['features'])->pluck('properties.name')->all())->toBe(['Visible']);

    DB::statement("UPDATE lgas SET boundary = ST_GeogFromText('SRID=4326;MULTIPOLYGON(((3.3 6.4, 3.5 6.4, 3.5 6.6, 3.3 6.6, 3.3 6.4)))'), centroid = ST_SetSRID(ST_MakePoint(3.4, 6.5), 4326)::geography WHERE id = ?", [$this->world->lgas['A1a']->id]);
    app(BoundaryImporter::class)->simplify();
    ThreatReport::factory()->in($this->world->lgas['A1a'])->create(['title' => 'LGA-wide', 'urgency' => 'immediate']);
    ThreatReport::factory()->in($this->world->lgas['A1a'])->at(6.45, 3.35, 2000)->create(['title' => 'Point', 'urgency' => 'priority']);
    ThreatReport::factory()->in($this->world->lgas['A1a'])->at(6.45, 3.35, 2000)->create(['title' => 'Withdrawn', 'status' => 'withdrawn', 'status_note' => 'x']);

    $threats = collect(layer($this->chief, '/map/layers/threats?'.VIEW)['features'])->keyBy('properties.title');
    expect($threats->keys()->sort()->values()->all())->toBe(['LGA-wide', 'Point'])
        ->and($threats['LGA-wide']['geometry']['type'])->toBe('MultiPolygon')
        ->and($threats['LGA-wide']['properties']['area'])->toBe('lga')
        ->and($threats['Point']['geometry']['type'])->toBe('Point');

    expect(collect(layer($this->chief, '/map/layers/threats?'.VIEW.'&urgencies[]=immediate')['features'])->pluck('properties.title')->all())->toBe(['LGA-wide']);
});

test('dashboards no longer send raw incident points', function () {
    mapFixture($this->world);

    foreach (['state_director', 'director_general', 'lga_chief'] as $who) {
        CrossFixtures::signIn($this->world->users[$who])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->missing('incidents.map7')
            ->has('map'));
    }
});
