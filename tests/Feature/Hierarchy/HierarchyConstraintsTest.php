<?php

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\Ward;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('an LGA cannot use a senatorial district from another state', function () {
    $lagos = State::factory()->create();
    $kanoDistrict = SenatorialDistrict::factory()->create();

    expect(fn () => Lga::factory()->create([
        'state_id' => $lagos->id,
        'senatorial_district_id' => $kanoDistrict->id,
    ]))->toThrow(QueryException::class, 'lgas_district_same_state_foreign');
});

test('an existing LGA cannot be moved to a district in another state', function () {
    $lga = Lga::factory()->create();
    $otherDistrict = SenatorialDistrict::factory()->create();

    expect(fn () => $lga->update(['senatorial_district_id' => $otherDistrict->id]))
        ->toThrow(QueryException::class, 'lgas_district_same_state_foreign');
});

test('a district with LGAs cannot be moved to another state', function () {
    $lga = Lga::factory()->create();
    $otherState = State::factory()->create();

    expect(fn () => $lga->senatorialDistrict->update(['state_id' => $otherState->id]))
        ->toThrow(QueryException::class, 'lgas_district_same_state_foreign');
});

test('a ward state must match its LGA state', function () {
    $lga = Lga::factory()->create();
    $otherState = State::factory()->create();

    expect(fn () => Ward::factory()->create(['lga_id' => $lga->id, 'state_id' => $otherState->id]))
        ->toThrow(QueryException::class, 'wards_lga_same_state_foreign');
});

test('an LGA code is unique within its state but may repeat across states', function () {
    $lga = Lga::factory()->create(['code' => 'ABC']);

    expect(Lga::factory()->create(['code' => 'ABC'])->code)->toBe('ABC');

    expect(fn () => Lga::factory()->create(['state_id' => $lga->state_id, 'code' => 'ABC']))
        ->toThrow(QueryException::class, 'lgas_state_id_code_unique');
});

test('an LGA name is unique within its state', function () {
    $lga = Lga::factory()->create(['name' => 'Fixture Name']);

    expect(fn () => Lga::factory()->create(['state_id' => $lga->state_id, 'name' => 'Fixture Name']))
        ->toThrow(QueryException::class, 'lgas_state_id_name_unique');
});

test('a ward code is unique within its LGA', function () {
    $ward = Ward::factory()->create(['code' => 'W1']);

    expect(fn () => Ward::factory()->create(['lga_id' => $ward->lga_id, 'code' => 'W1']))
        ->toThrow(QueryException::class, 'wards_lga_id_code_unique');
});

test('senatorial district codes are globally unique', function () {
    $district = SenatorialDistrict::factory()->create();

    expect(fn () => SenatorialDistrict::factory()->create(['code' => $district->code]))
        ->toThrow(QueryException::class, 'senatorial_districts_code_unique');
});

test('reference data with children cannot be deleted', function () {
    $ward = Ward::factory()->create();

    expect(fn () => $ward->lga->state->delete())->toThrow(QueryException::class);
    expect(fn () => $ward->lga->delete())->toThrow(QueryException::class);
});

test('the same-state composite foreign keys exist', function () {
    $constraints = DB::table('pg_constraint')
        ->whereIn('conname', ['lgas_district_same_state_foreign', 'wards_lga_same_state_foreign'])
        ->pluck('contype', 'conname')
        ->all();

    expect($constraints)->toBe([
        'lgas_district_same_state_foreign' => 'f',
        'wards_lga_same_state_foreign' => 'f',
    ]);
});

test('geography columns use SRID 4326 and have GIST indexes', function () {
    $columns = collect(DB::select("
        select f_table_name as table_name, f_geography_column as column_name, type, srid
        from geography_columns
        where f_table_name in ('states', 'lgas')
        order by f_table_name, f_geography_column
    "))->map(fn ($row) => "{$row->table_name}.{$row->column_name}:{$row->type}:{$row->srid}")->all();

    expect($columns)->toBe([
        'lgas.boundary:MultiPolygon:4326',
        'lgas.centroid:Point:4326',
        'states.boundary:MultiPolygon:4326',
        'states.centroid:Point:4326',
    ]);

    $gist = collect(DB::select("
        select tablename, indexdef from pg_indexes
        where tablename in ('states', 'lgas') and indexdef ilike '%using gist%'
    "))->count();

    expect($gist)->toBe(4);
});

test('every foreign key column is indexed', function () {
    $expected = [
        'states' => ['geopolitical_zone_id'],
        'senatorial_districts' => ['state_id'],
        'lgas' => ['state_id', 'senatorial_district_id'],
        'wards' => ['lga_id', 'state_id'],
    ];

    foreach ($expected as $table => $columns) {
        $indexes = collect(DB::select('select indexdef from pg_indexes where tablename = ?', [$table]))->pluck('indexdef');

        foreach ($columns as $column) {
            expect($indexes->contains(fn (string $def) => str_contains($def, "btree ({$column})")))
                ->toBeTrue("{$table}.{$column} has no index");
        }
    }
});
