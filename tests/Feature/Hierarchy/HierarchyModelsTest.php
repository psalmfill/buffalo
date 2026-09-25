<?php

use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

test('every hierarchy model gets a ULID on create and routes by it', function (string $model) {
    /** @var class-string<Model> $model */
    $record = $model::factory()->create();

    expect($record->getRouteKeyName())->toBe('ulid')
        ->and(Str::isUlid($record->ulid))->toBeTrue()
        ->and($record->getRouteKey())->toBe($record->ulid)
        ->and($record->getKey())->toBeInt()
        ->and($record->resolveRouteBinding($record->ulid)?->is($record))->toBeTrue();
})->with([GeopoliticalZone::class, State::class, SenatorialDistrict::class, Lga::class, Ward::class]);

test('route binding rejects numeric ids and malformed ULIDs', function () {
    $lga = Lga::factory()->create();

    expect(fn () => $lga->resolveRouteBinding((string) $lga->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $lga->resolveRouteBinding('not-a-ulid'))->toThrow(ModelNotFoundException::class);
});

test('numeric ids and geometry are hidden from serialisation', function () {
    $ward = Ward::factory()->create();
    $lga = $ward->lga;

    expect(array_keys($lga->toArray()))->not->toContain('id', 'state_id', 'senatorial_district_id', 'centroid', 'boundary')
        ->and(array_keys($lga->state->toArray()))->not->toContain('id', 'geopolitical_zone_id', 'centroid', 'boundary')
        ->and(array_keys($ward->toArray()))->not->toContain('id', 'lga_id', 'state_id')
        ->and($lga->toArray())->toHaveKey('ulid');
});

test('relationships connect every level in both directions', function () {
    $zone = GeopoliticalZone::factory()->create();
    $state = State::factory()->for($zone)->create();
    $district = SenatorialDistrict::factory()->for($state)->create();
    $lga = Lga::factory()->create(['senatorial_district_id' => $district->id]);
    $ward = Ward::factory()->create(['lga_id' => $lga->id]);

    expect($zone->states->modelKeys())->toBe([$state->id])
        ->and($state->geopoliticalZone->is($zone))->toBeTrue()
        ->and($state->senatorialDistricts->modelKeys())->toBe([$district->id])
        ->and($state->wards->modelKeys())->toBe([$ward->id])
        ->and($district->state->is($state))->toBeTrue()
        ->and($district->lgas->modelKeys())->toBe([$lga->id])
        ->and($lga->state->is($state))->toBeTrue()
        ->and($lga->senatorialDistrict->is($district))->toBeTrue()
        ->and($lga->wards->modelKeys())->toBe([$ward->id])
        ->and($ward->lga->is($lga))->toBeTrue()
        ->and($ward->state->is($state))->toBeTrue();
});

test('State::lgas and GeopoliticalZone::lgas go through the intermediate level', function () {
    $zone = GeopoliticalZone::factory()->create();
    $stateA = State::factory()->for($zone)->create();
    $stateB = State::factory()->for($zone)->create();
    $otherZoneState = State::factory()->create();

    $a1 = Lga::factory()->create(['state_id' => $stateA->id]);
    $a2 = Lga::factory()->create(['state_id' => $stateA->id]);
    $b1 = Lga::factory()->create(['state_id' => $stateB->id]);
    Lga::factory()->create(['state_id' => $otherZoneState->id]);

    expect($stateA->lgas->modelKeys())->toEqualCanonicalizing([$a1->id, $a2->id])
        ->and($stateB->lgas->modelKeys())->toBe([$b1->id])
        ->and($zone->lgas->modelKeys())->toEqualCanonicalizing([$a1->id, $a2->id, $b1->id]);
});

test('the active scope excludes deactivated records', function (string $model) {
    /** @var class-string<Model> $model */
    $active = $model::factory()->create();
    $inactive = $model::factory()->inactive()->create();

    $ids = $model::query()->active()->pluck('id')->all();

    expect($ids)->toContain($active->getKey())->not->toContain($inactive->getKey());
})->with([GeopoliticalZone::class, State::class, SenatorialDistrict::class, Lga::class, Ward::class]);

test('factories produce clearly synthetic names', function () {
    $ward = Ward::factory()->create();

    expect($ward->name)->toStartWith('Test Ward ')
        ->and($ward->lga->name)->toStartWith('Test LGA ')
        ->and($ward->lga->senatorialDistrict->name)->toStartWith('Test District ')
        ->and($ward->state->name)->toStartWith('Test State ')
        ->and($ward->state->geopoliticalZone->name)->toStartWith('Test Zone ')
        ->and($ward->lga->code)->toMatch('/^[A-Z]{3}$/');
});
