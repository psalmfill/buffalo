<?php

use App\Models\Community;
use App\Models\Lga;
use App\Support\Dashboard\MapView;
use App\Support\Geo\BoundaryAttribution;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/** A closed square ring [lng, lat] with its south-west corner at ($lat, $lng). */
function square(float $lat, float $lng, float $size = 0.2): array
{
    return [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]]];
}

/**
 * Writes a FeatureCollection to a temporary file.
 *
 * @param  list<array<string, mixed>>  $features
 */
function geojsonFile(array $features, array $extra = []): string
{
    $path = sys_get_temp_dir().'/buffalo-boundaries-'.Str::lower(Str::random(10)).'.geojson';
    File::put($path, json_encode(['type' => 'FeatureCollection', ...$extra, 'features' => $features]));

    return $path;
}

function feature(Lga $lga, array $coordinates, string $type = 'Polygon', array $properties = []): array
{
    return [
        'type' => 'Feature',
        'properties' => ['lga' => $lga->code, 'state' => $lga->state->code, ...$properties],
        'geometry' => ['type' => $type, 'coordinates' => $coordinates],
    ];
}

/**
 * Runs the import and returns [exit code, output with whitespace collapsed].
 *
 * @return array{0: int, 1: string}
 */
function importBoundaries(string $file, array $options = []): array
{
    $code = Artisan::call('buffalo:import-boundaries', [
        'file' => $file,
        '--code-property' => 'lga',
        '--state-property' => 'state',
        '--attribution' => 'Test boundaries, CC BY 4.0',
        ...$options,
    ]);

    return [$code, (string) preg_replace('/\s+/', ' ', Artisan::output())];
}

function boundaryOf(Lga $lga): ?string
{
    return DB::table('lgas')->where('id', $lga->id)->value(DB::raw('ST_AsText(boundary)'));
}

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    app(JurisdictionContext::class)->system();
});

test('features are matched to LGAs by code properties; centroids lie inside their shapes', function () {
    ['A1a' => $a, 'A1b' => $b] = $this->world->lgas;
    $file = geojsonFile([
        feature($a, square(6.0, 3.0)),
        feature($b, [square(6.0, 3.2), square(6.5, 3.2, 0.1)], 'MultiPolygon'),
        ['type' => 'Feature', 'id' => 'stray', 'properties' => ['lga' => 'ZZZ', 'state' => 'ZZ'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(8.0, 8.0)]],
    ]);

    [$code, $output] = importBoundaries($file, ['--user' => $this->world->users['super_admin']->email]);

    expect($code)->toBe(0)
        ->and($output)->toContain('3 features, 2 matched, 1 unmatched, 0 repaired, 0 errors; 2 LGAs without a boundary')
        ->and($output)->toContain('Feature stray (ZZ/ZZZ)')
        ->and($output)->toContain($this->world->lgas['A2']->name)
        ->and($output)->toContain($this->world->lgas['B1']->name);

    foreach ([$a, $b] as $lga) {
        $row = DB::selectOne('SELECT GeometryType(boundary::geometry) AS type, ST_Covers(boundary, centroid) AS inside FROM lgas WHERE id = ?', [$lga->id]);
        expect($row->type)->toBe('MULTIPOLYGON')->and($row->inside)->toBeTrue();
    }

    expect(boundaryOf($this->world->lgas['A2']))->toBeNull();

    $source = DB::table('boundary_sources')->sole();
    expect($source->level)->toBe('lga')
        ->and($source->attribution)->toBe('Test boundaries, CC BY 4.0')
        ->and($source->source_name)->toBe(basename($file))
        ->and($source->feature_count)->toBe(2)
        ->and($source->synthetic)->toBeFalse()
        ->and($source->imported_by)->toBe($this->world->users['super_admin']->id);

    // Simplified display geometries at three zoom levels, for LGAs and the derived state.
    expect(DB::table('boundary_simplified')->where('place_type', 'lga')->count())->toBe(6)
        ->and(DB::table('boundary_simplified')->where('place_type', 'state')->count())->toBe(3);
});

test('features can be matched with a mapping CSV', function () {
    $a = $this->world->lgas['A1a'];
    $mapping = sys_get_temp_dir().'/buffalo-mapping-'.Str::random(8).'.csv';
    File::put($mapping, "feature_id,state_code,lga_code\nNGA-1,{$a->state->code},".strtolower($a->code)."\n");

    $file = geojsonFile([
        ['type' => 'Feature', 'id' => 'NGA-1', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(6.0, 3.0)]],
        ['type' => 'Feature', 'properties' => ['fid' => 'NGA-2'], 'geometry' => ['type' => 'Polygon', 'coordinates' => square(7.0, 3.0)]],
    ]);

    [$code, $output] = importBoundaries($file, ['--code-property' => null, '--state-property' => null, '--mapping' => $mapping, '--id-property' => 'fid']);

    expect($code)->toBe(0)
        ->and($output)->toContain('2 features, 1 matched, 1 unmatched')
        ->and($output)->toContain('Feature NGA-2')
        ->and(boundaryOf($a))->not->toBeNull();

    // A malformed mapping file is refused.
    File::put($mapping, "id,code\nNGA-1,X\n");
    expect(importBoundaries($file, ['--code-property' => null, '--mapping' => $mapping])[0])->toBe(1);
});

test('an invalid geometry is repaired and reported', function () {
    $a = $this->world->lgas['A1a'];
    // A self-intersecting "bow tie".
    $bowTie = [[[3.0, 6.0], [3.2, 6.2], [3.2, 6.0], [3.0, 6.2], [3.0, 6.0]]];

    [$code, $output] = importBoundaries(geojsonFile([feature($a, $bowTie)]));

    expect($code)->toBe(0)
        ->and($output)->toContain('1 repaired')
        ->and($output)->toContain('repaired (Self-intersection');

    $row = DB::selectOne('SELECT ST_IsValid(boundary::geometry) AS valid, GeometryType(boundary::geometry) AS type FROM lgas WHERE id = ?', [$a->id]);
    expect($row->valid)->toBeTrue()->and($row->type)->toBe('MULTIPOLYGON');
});

test('geometries outside Nigeria, of the wrong type, or matched twice are rejected and nothing is written', function () {
    ['A1a' => $a, 'A1b' => $b] = $this->world->lgas;

    [$code, $output] = importBoundaries(geojsonFile([
        feature($a, square(6.0, 3.0)),
        feature($b, square(51.4, -0.2)),
    ]));
    expect($code)->toBe(1)
        ->and($output)->toContain("outside Nigeria's bounding box")
        ->and($output)->toContain('Nothing was written');

    [$code, $output] = importBoundaries(geojsonFile([
        feature($a, [3.1, 6.1], 'Point'),
        feature($b, square(6.0, 3.2)),
        feature($b, square(6.0, 3.4)),
    ]));
    expect($code)->toBe(1)
        ->and($output)->toContain('must be a Polygon or MultiPolygon, not Point')
        ->and($output)->toContain('is matched more than once');

    // Another coordinate reference system is refused outright.
    $projected = geojsonFile([feature($a, square(6.0, 3.0))], ['crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:EPSG::32631']]]);
    expect(importBoundaries($projected)[0])->toBe(1);

    expect(boundaryOf($a))->toBeNull()
        ->and(boundaryOf($b))->toBeNull()
        ->and(DB::table('boundary_sources')->count())->toBe(0);
});

test('a dry run validates and reports but writes nothing', function () {
    $a = $this->world->lgas['A1a'];

    [$code, $output] = importBoundaries(geojsonFile([feature($a, square(6.0, 3.0))]), ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('1 matched')
        ->and($output)->toContain('Dry run: nothing was written')
        ->and(boundaryOf($a))->toBeNull()
        ->and(DB::table('boundary_sources')->count())->toBe(0)
        ->and(DB::table('boundary_simplified')->count())->toBe(0);
});

test('state boundaries and centroids are derived from their LGAs unless states were imported', function () {
    ['A1a' => $a, 'A1b' => $b] = $this->world->lgas;
    $state = $a->state;

    importBoundaries(geojsonFile([feature($a, square(6.0, 3.0)), feature($b, square(6.0, 3.2))]));

    $row = DB::selectOne(
        'SELECT ST_Area(s.boundary) AS area, (SELECT sum(ST_Area(boundary)) FROM lgas WHERE state_id = s.id) AS parts, ST_Covers(s.boundary, s.centroid) AS inside
         FROM states s WHERE s.id = ?',
        [$state->id],
    );
    expect(abs($row->area - $row->parts) / $row->parts)->toBeLessThan(0.001)
        ->and($row->inside)->toBeTrue();

    // Once states have their own import, LGA imports no longer derive them.
    $stateFile = geojsonFile([[
        'type' => 'Feature',
        'properties' => ['code' => strtolower($state->code)],
        'geometry' => ['type' => 'Polygon', 'coordinates' => square(5.9, 2.9, 0.6)],
    ]]);
    expect(Artisan::call('buffalo:import-boundaries', ['file' => $stateFile, '--level' => 'state', '--code-property' => 'code', '--attribution' => 'States']))->toBe(0);

    [, $output] = importBoundaries(geojsonFile([feature($a, square(6.1, 3.0))]));
    expect($output)->toContain('0 state boundaries derived')
        ->and(DB::selectOne('SELECT ST_Area(boundary) > 3e9 AS big FROM states WHERE id = ?', [$state->id])->big)->toBeTrue();
});

test('the post-import check reports records outside their new LGA boundary without changing them', function () {
    $a = $this->world->lgas['A1a'];
    // Recorded before any boundary existed.
    $outside = Community::factory()->in($a)->at(7.5, 5.0)->create();
    $inside = Community::factory()->in($a)->at(6.1, 3.1)->create();

    [$code, $output] = importBoundaries(geojsonFile([feature($a, square(6.0, 3.0))]));

    expect($code)->toBe(0)
        ->and($output)->toContain('1 existing records lie outside their LGA boundary (not changed): communities 1');

    preg_match('/Report: (\S+\.csv)/', $output, $match);
    $report = File::get(str_starts_with($match[1], '/') ? $match[1] : base_path($match[1]));

    expect($report)->toContain($outside->ulid)
        ->and($report)->not->toContain($inside->ulid)
        ->and($report)->not->toContain($outside->name)
        ->and(Community::query()->whereKey($outside->id)->exists())->toBeTrue();
});

test('after an import, new points outside the LGA boundary are rejected', function () {
    $a = $this->world->lgas['A1a'];
    importBoundaries(geojsonFile([feature($a, square(6.0, 3.0))]));

    expect(fn () => DB::transaction(fn () => Community::factory()->in($a)->at(7.5, 5.0)->create()))
        ->toThrow(QueryException::class, 'outside its LGA boundary');

    Community::factory()->in($a)->at(6.1, 3.1)->create();
    expect(Community::query()->where('lga_id', $a->id)->count())->toBe(1);
});

test('maps get the outline and the attribution after an import', function () {
    $a = $this->world->lgas['A1a'];
    expect(BoundaryAttribution::current())->toBeNull()
        ->and(MapView::for($a)['outline'])->toBeNull();

    importBoundaries(geojsonFile([feature($a, square(6.0, 3.0))]));

    $view = MapView::for($a);
    expect(BoundaryAttribution::current())->toBe('Test boundaries, CC BY 4.0')
        ->and($view['outline']['type'] ?? null)->toBe('MultiPolygon')
        ->and($view['zoom'])->toBe(10);

    $this->actingAs($this->world->users['lga_chief'])
        ->get("/lgas/{$a->ulid}")
        ->assertInertia(fn ($page) => $page
            ->where('boundaryAttribution', 'Test boundaries, CC BY 4.0')
            ->where('overview.map.outline.type', 'MultiPolygon'));
});

test('demo boundaries are generated only in the local environment and are labelled synthetic', function () {
    expect(Artisan::call('buffalo:generate-demo-boundaries'))->toBe(1)
        ->and(Artisan::output())->toContain('runs only in the local environment')
        ->and(DB::table('boundary_sources')->count())->toBe(0);

    app()->detectEnvironment(fn () => 'local');

    try {
        expect(Artisan::call('buffalo:generate-demo-boundaries'))->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    $source = DB::table('boundary_sources')->sole();
    expect($source->synthetic)->toBeTrue()
        ->and($source->attribution)->toContain('SYNTHETIC')
        ->and(DB::table('lgas')->whereNull('boundary')->count())->toBe(0)
        ->and(DB::selectOne('SELECT bool_and(ST_Covers(boundary, centroid)) AS ok FROM lgas')->ok)->toBeTrue()
        // Demo squares never overlap within a state.
        ->and(DB::selectOne('SELECT count(*) AS n FROM lgas x JOIN lgas y ON x.state_id = y.state_id AND x.id < y.id WHERE ST_Overlaps(x.boundary::geometry, y.boundary::geometry)')->n)->toBe(0);
});
