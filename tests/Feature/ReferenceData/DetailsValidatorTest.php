<?php

use App\Support\DynamicFields\DetailsValidator;
use App\Support\DynamicFields\SchemaValidator;
use Illuminate\Validation\ValidationException;

function detailsSchema(array $schema = []): array
{
    return app(SchemaValidator::class)->validate($schema ?: [
        ['key' => 'road_name', 'label' => 'Road name', 'type' => 'text', 'max_length' => 20],
        ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ['key' => 'vehicles', 'label' => 'Vehicles', 'type' => 'integer', 'min' => 1, 'max' => 50],
        ['key' => 'loss', 'label' => 'Loss', 'type' => 'number', 'min' => 0],
        ['key' => 'responded', 'label' => 'Responded', 'type' => 'boolean'],
        ['key' => 'seen_on', 'label' => 'Seen on', 'type' => 'date'],
        ['key' => 'seen_at', 'label' => 'Seen at', 'type' => 'datetime'],
        ['key' => 'cause', 'label' => 'Cause', 'type' => 'select', 'options' => [
            ['value' => 'speed', 'label' => 'Overspeeding'],
            ['value' => 'brakes', 'label' => 'Brake failure'],
            ['value' => 'old', 'label' => 'Old cause', 'deprecated' => true],
        ]],
        ['key' => 'types', 'label' => 'Vehicle types', 'type' => 'multiselect', 'options' => [
            ['value' => 'car', 'label' => 'Car'],
            ['value' => 'bus', 'label' => 'Bus'],
            ['value' => 'cart', 'label' => 'Cart', 'deprecated' => true],
        ]],
        ['key' => 'legacy', 'label' => 'Legacy', 'type' => 'text', 'deprecated' => true],
    ]);
}

/**
 * @return array<string, list<string>>|array<string, mixed>
 */
function details(mixed $details, array $existing = [], ?array $schema = null): array
{
    try {
        return ['ok' => app(DetailsValidator::class)->validate($schema ?? detailsSchema(), $details, $existing)];
    } catch (ValidationException $exception) {
        return ['errors' => $exception->errors()];
    }
}

test('valid details of every type are cast and cleaned', function () {
    $result = details([
        'road_name' => 'Lagos-Ibadan',
        'notes' => 'Long notes',
        'vehicles' => '3',
        'loss' => '1500.50',
        'responded' => '1',
        'seen_on' => '2026-09-01',
        'seen_at' => '2026-09-01T14:30',
        'cause' => 'speed',
        'types' => ['bus', 'car'],
    ]);

    expect($result['ok'])->toBe([
        'road_name' => 'Lagos-Ibadan',
        'notes' => 'Long notes',
        'vehicles' => 3,
        'loss' => 1500.5,
        'responded' => true,
        'seen_on' => '2026-09-01',
        'seen_at' => '2026-09-01T14:30',
        'cause' => 'speed',
        // Stored in the schema's option order.
        'types' => ['car', 'bus'],
    ]);
});

test('whole numbers, false booleans and seconds are normalised', function () {
    expect(details(['loss' => 2000, 'responded' => false, 'seen_at' => '2026-09-01 14:30:59'])['ok'])
        ->toBe(['loss' => 2000, 'responded' => false, 'seen_at' => '2026-09-01T14:30']);
});

test('empty optional values are left out; an empty payload is valid', function () {
    expect(details(['road_name' => null, 'types' => [], 'responded' => null])['ok'])->toBe([])
        ->and(details([])['ok'])->toBe([])
        ->and(details(null)['ok'])->toBe([]);
});

test('unknown keys are rejected, not dropped', function () {
    expect(details(['road_name' => 'A1', 'colour' => 'red'])['errors'])
        ->toHaveKey('details.colour')
        ->not->toHaveKey('details.road_name');
});

test('the payload must be an object', function () {
    expect(details(['a', 'b'])['errors'])->toHaveKey('details')
        ->and(details('text')['errors'])->toHaveKey('details');
});

test('each type rejects wrong values', function (array $payload, string $errorKey) {
    expect(details($payload)['errors'])->toHaveKey($errorKey);
})->with([
    'text too long (max_length)' => [['road_name' => str_repeat('x', 21)], 'details.road_name'],
    'text not a string' => [['road_name' => ['a']], 'details.road_name'],
    'textarea over 5000' => [['notes' => str_repeat('x', 5001)], 'details.notes'],
    'integer with a fraction' => [['vehicles' => 2.5], 'details.vehicles'],
    'integer below min' => [['vehicles' => 0], 'details.vehicles'],
    'integer above max' => [['vehicles' => 51], 'details.vehicles'],
    'number not numeric' => [['loss' => 'a lot'], 'details.loss'],
    'number below min' => [['loss' => -1], 'details.loss'],
    'boolean not boolean' => [['responded' => 'maybe'], 'details.responded'],
    'date wrong format' => [['seen_on' => '01/09/2026'], 'details.seen_on'],
    'date impossible' => [['seen_on' => '2026-02-30'], 'details.seen_on'],
    'datetime wrong format' => [['seen_at' => 'yesterday'], 'details.seen_at'],
    'select unknown option' => [['cause' => 'weather'], 'details.cause'],
    'multiselect not a list' => [['types' => 'car'], 'details.types'],
    'multiselect unknown option' => [['types' => ['car', 'plane']], 'details.types.1'],
    'multiselect duplicates' => [['types' => ['car', 'car']], 'details.types.0'],
]);

test('required fields must be present; false satisfies a required boolean', function () {
    $schema = detailsSchema([
        ['key' => 'road_name', 'label' => 'Road name', 'type' => 'text', 'required' => true],
        ['key' => 'responded', 'label' => 'Responded', 'type' => 'boolean', 'required' => true],
    ]);

    expect(details([], schema: $schema)['errors'])->toHaveKeys(['details.road_name', 'details.responded'])
        ->and(details(['road_name' => '', 'responded' => false], schema: $schema)['errors'])->toHaveKey('details.road_name')->not->toHaveKey('details.responded')
        ->and(details(['road_name' => 'A1', 'responded' => false], schema: $schema)['ok'])->toBe(['road_name' => 'A1', 'responded' => false]);
});

test('error messages use the field label', function () {
    expect(details(['vehicles' => 0])['errors']['details.vehicles'][0])->toContain('Vehicles');
});

test('deprecated options cannot be chosen for new values', function () {
    expect(details(['cause' => 'old'])['errors'])->toHaveKey('details.cause')
        ->and(details(['types' => ['cart']])['errors'])->toHaveKey('details.types.0');
});

test('deprecated options the record already has are kept', function () {
    expect(details(['cause' => 'old'], ['cause' => 'old'])['ok'])->toBe(['cause' => 'old'])
        ->and(details(['types' => ['car', 'cart']], ['types' => ['cart']])['ok'])->toBe(['types' => ['car', 'cart']]);
});

test('deprecated fields cannot be filled for new values but keep a saved value', function () {
    expect(details(['legacy' => 'x'])['errors'])->toHaveKey('details.legacy')
        ->and(details(['legacy' => 'saved'], ['legacy' => 'saved'])['ok'])->toBe(['legacy' => 'saved'])
        ->and(details(['legacy' => 'changed'], ['legacy' => 'saved'])['errors'])->toHaveKey('details.legacy');
});
