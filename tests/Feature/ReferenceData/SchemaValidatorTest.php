<?php

use App\Support\DynamicFields\FieldDefinition;
use App\Support\DynamicFields\FieldType;
use App\Support\DynamicFields\SchemaValidator;
use Illuminate\Validation\ValidationException;

/**
 * The validation errors for $schema, keyed by path (empty if valid).
 *
 * @return array<string, list<string>>
 */
function schemaErrors(mixed $schema): array
{
    try {
        app(SchemaValidator::class)->validate($schema);

        return [];
    } catch (ValidationException $exception) {
        return $exception->errors();
    }
}

/**
 * @return array<string, list<string>>
 */
function evolutionErrors(array $current, array $proposed): array
{
    $validator = app(SchemaValidator::class);

    try {
        $validator->assertCompatible($validator->validate($current), $validator->validate($proposed));

        return [];
    } catch (ValidationException $exception) {
        return $exception->errors();
    }
}

function field(array $overrides = []): array
{
    return ['key' => 'ransom_amount', 'label' => 'Ransom demanded', 'type' => 'number', ...$overrides];
}

function selectField(array $overrides = []): array
{
    return field(['key' => 'location_type', 'label' => 'Location', 'type' => 'select', 'options' => [
        ['value' => 'home', 'label' => 'Home'],
        ['value' => 'farm', 'label' => 'Farm'],
    ], ...$overrides]);
}

test('a valid schema with every field type is accepted and normalised', function () {
    $schema = [
        ['key' => 'a_text', 'label' => 'Text', 'type' => 'text', 'max_length' => 100, 'help' => 'Help'],
        ['key' => 'a_textarea', 'label' => 'Textarea', 'type' => 'textarea', 'required' => true],
        ['key' => 'an_integer', 'label' => 'Integer', 'type' => 'integer', 'min' => 1, 'max' => 10],
        ['key' => 'a_number', 'label' => 'Number', 'type' => 'number', 'min' => 0.5],
        ['key' => 'a_boolean', 'label' => 'Boolean', 'type' => 'boolean'],
        ['key' => 'a_date', 'label' => 'Date', 'type' => 'date'],
        ['key' => 'a_datetime', 'label' => 'Datetime', 'type' => 'datetime'],
        selectField(),
        selectField(['key' => 'weapons', 'type' => 'multiselect']),
        field(['key' => 'old_field', 'deprecated' => true]),
    ];

    $fields = app(SchemaValidator::class)->validate($schema);

    expect($fields)->toHaveCount(10)
        ->and($fields[0])->toBeInstanceOf(FieldDefinition::class)
        ->and($fields[2]->type)->toBe(FieldType::Integer)
        ->and($fields[2]->min)->toBe(1)
        ->and($fields[3]->min)->toBe(0.5)
        ->and($fields[0]->toArray())->toBe(['key' => 'a_text', 'label' => 'Text', 'type' => 'text', 'required' => false, 'max_length' => 100, 'help' => 'Help'])
        ->and($fields[9]->toArray())->toHaveKey('deprecated', true)
        ->and(schemaErrors([]))->toBe([]);
});

test('the schema must be a list of objects', function (mixed $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'not an array' => ['nope', 'schema'],
    'an object, not a list' => [['a' => field()], 'schema'],
    'a field that is not an object' => [['text'], 'schema.0'],
    'a field that is a list' => [[['key', 'label']], 'schema.0'],
]);

test('keys must be present, snake_case and unique', function (array $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'missing' => [[field(['key' => null])], 'schema.0.key'],
    'empty' => [[field(['key' => ''])], 'schema.0.key'],
    'uppercase' => [[field(['key' => 'RansomAmount'])], 'schema.0.key'],
    'with spaces' => [[field(['key' => 'ransom amount'])], 'schema.0.key'],
    'starting with a digit' => [[field(['key' => '1st_field'])], 'schema.0.key'],
    'with a hyphen' => [[field(['key' => 'ransom-amount'])], 'schema.0.key'],
    'too long' => [[field(['key' => str_repeat('a', 65)])], 'schema.0.key'],
    'duplicate' => [[field(), field(['label' => 'Again'])], 'schema.1.key'],
]);

test('labels, types and flags are checked', function (array $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'missing label' => [[field(['label' => ''])], 'schema.0.label'],
    'label too long' => [[field(['label' => str_repeat('x', 256)])], 'schema.0.label'],
    'unknown type' => [[field(['type' => 'currency'])], 'schema.0.type'],
    'missing type' => [[field(['type' => null])], 'schema.0.type'],
    'required not boolean' => [[field(['required' => 'yes'])], 'schema.0.required'],
    'deprecated not boolean' => [[field(['deprecated' => 1])], 'schema.0.deprecated'],
    'required and deprecated' => [[field(['required' => true, 'deprecated' => true])], 'schema.0.required'],
    'help too long' => [[field(['help' => str_repeat('x', 501)])], 'schema.0.help'],
    'unknown property' => [[field(['colour' => 'red'])], 'schema.0'],
]);

test('options are required for select fields and only allowed there', function (array $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'select without options' => [[selectField(['options' => null])], 'schema.0.options'],
    'multiselect with empty options' => [[selectField(['type' => 'multiselect', 'options' => []])], 'schema.0.options'],
    'options on a text field' => [[field(['type' => 'text', 'options' => [['value' => 'a', 'label' => 'A']]])], 'schema.0.options'],
    'option without value' => [[selectField(['options' => [['label' => 'Home']]])], 'schema.0.options.0.value'],
    'option without label' => [[selectField(['options' => [['value' => 'home']]])], 'schema.0.options.0.label'],
    'duplicate option value' => [[selectField(['options' => [['value' => 'home', 'label' => 'Home'], ['value' => 'home', 'label' => 'House']]])], 'schema.0.options.1.value'],
    'unknown option property' => [[selectField(['options' => [['value' => 'home', 'label' => 'Home', 'colour' => 'red']]])], 'schema.0.options.0'],
    'every option deprecated' => [[selectField(['options' => [['value' => 'home', 'label' => 'Home', 'deprecated' => true]]])], 'schema.0.options'],
]);

test('a deprecated select field may have only deprecated options', function () {
    expect(schemaErrors([selectField(['deprecated' => true, 'options' => [['value' => 'home', 'label' => 'Home', 'deprecated' => true]]])]))->toBe([]);
});

test('min and max apply to numeric fields and must be ordered', function (array $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'min on text' => [[field(['type' => 'text', 'min' => 1])], 'schema.0.min'],
    'max on a date' => [[field(['type' => 'date', 'max' => 1])], 'schema.0.max'],
    'non-numeric min' => [[field(['min' => 'ten'])], 'schema.0.min'],
    'fractional bound on an integer' => [[field(['type' => 'integer', 'max' => 2.5])], 'schema.0.max'],
    'min above max' => [[field(['min' => 10, 'max' => 5])], 'schema.0.max'],
]);

test('max_length applies to text fields within their limit', function (array $schema, string $errorKey) {
    expect(schemaErrors($schema))->toHaveKey($errorKey);
})->with([
    'on a number' => [[field(['max_length' => 10])], 'schema.0.max_length'],
    'zero' => [[field(['type' => 'text', 'max_length' => 0])], 'schema.0.max_length'],
    'above the text limit' => [[field(['type' => 'text', 'max_length' => 256])], 'schema.0.max_length'],
    'above the textarea limit' => [[field(['type' => 'textarea', 'max_length' => 5001])], 'schema.0.max_length'],
    'not a whole number' => [[field(['type' => 'text', 'max_length' => '100'])], 'schema.0.max_length'],
]);

test('an in-use schema may add, relabel, reorder and deprecate fields and add or deprecate options', function () {
    $current = [field(), selectField()];

    $proposed = [
        selectField(['label' => 'Where it happened', 'options' => [
            ['value' => 'home', 'label' => 'At home', 'deprecated' => true],
            ['value' => 'farm', 'label' => 'Farm'],
            ['value' => 'school', 'label' => 'School'],
        ]]),
        field(['deprecated' => true, 'label' => 'Ransom (old)']),
        field(['key' => 'negotiator', 'label' => 'Negotiator', 'type' => 'text']),
    ];

    expect(evolutionErrors($current, $proposed))->toBe([]);
});

test('an in-use schema cannot rename or remove a key', function () {
    $errors = evolutionErrors([field(), selectField()], [field(['key' => 'ransom_naira']), selectField()]);

    expect($errors['schema'][0])->toContain('"ransom_amount" cannot be removed or renamed')->toContain('Deprecate it instead');

    expect(evolutionErrors([field(), selectField()], [selectField()]))->toHaveKey('schema');
});

test('an in-use schema cannot change a field type', function () {
    $errors = evolutionErrors([field()], [field(['type' => 'integer'])]);

    expect($errors['schema.0.type'][0])->toBe('The type of "ransom_amount" cannot change from number to integer because this incident type is in use.');
});

test('an in-use schema cannot remove or rename an option', function () {
    $removed = evolutionErrors([selectField()], [selectField(['options' => [['value' => 'home', 'label' => 'Home']]])]);
    $renamed = evolutionErrors([selectField()], [selectField(['options' => [['value' => 'home', 'label' => 'Home'], ['value' => 'farmland', 'label' => 'Farm']]])]);

    expect($removed['schema.0.options'][0])->toContain('"farm" of "location_type" cannot be removed')
        ->and($renamed)->toHaveKey('schema.0.options');
});
