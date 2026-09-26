<?php

use App\Models\Lga;
use App\Models\Person;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

const PLAIN = [
    'date_of_birth' => '1984-03-12',
    'nin' => '12345678901',
    'phone' => '+2348031234567',
    'address' => '14 Marina Road, Apapa',
];

function personWithProtectedFields(array $overrides = []): Person
{
    return Person::factory()->in(Lga::factory()->create())->create([...PLAIN, ...$overrides]);
}

test('protected fields are ciphertext in the database and plaintext on the model', function () {
    $person = personWithProtectedFields();
    $row = (array) DB::table('persons')->where('id', $person->id)->first();

    foreach (PLAIN as $column => $value) {
        expect($row[$column])->toStartWith('nacl:')
            ->and($row[$column])->not->toContain($value);
    }

    $fresh = Person::query()->findOrFail($person->id);

    foreach (PLAIN as $column => $value) {
        expect($fresh->{$column})->toBe($value);
    }
});

test('the database refuses protected fields in clear', function (string $column) {
    $person = personWithProtectedFields();

    expect(fn () => DB::table('persons')->where('id', $person->id)->update([$column => PLAIN[$column]]))
        ->toThrow(QueryException::class, "persons_{$column}_encrypted");
})->with(array_keys(PLAIN));

test('blind indexes find exact matches only', function () {
    $person = personWithProtectedFields();
    personWithProtectedFields(['nin' => '12345678902', 'phone' => '08039999999', 'date_of_birth' => '1990-01-01']);

    expect(Person::query()->whereBlind('nin', 'nin_bidx', '12345678901')->pluck('id')->all())->toBe([$person->id])
        ->and(Person::query()->whereBlind('nin', 'nin_bidx', '1234567890')->count())->toBe(0)
        ->and(Person::query()->whereBlind('phone', 'phone_bidx', '+2348031234567')->pluck('id')->all())->toBe([$person->id])
        ->and(Person::query()->whereBlind('date_of_birth', 'dob_bidx', '1984-03-12')->pluck('id')->all())->toBe([$person->id]);

    // One row per index and person; the index values are not the plaintext.
    $indexes = DB::table('blind_indexes')->where('indexable_id', $person->id)->pluck('value', 'name')->all();

    expect(array_keys($indexes))->toEqualCanonicalizing(['dob_bidx', 'nin_bidx', 'phone_bidx']);

    foreach ($indexes as $value) {
        expect($value)->not->toContain('12345678901')->not->toContain('8031234567');
    }
});

test('phone numbers written differently match', function (string $written) {
    $person = personWithProtectedFields(['phone' => $written]);

    expect($person->fresh()?->phone)->toBe('+2348031234567');

    foreach (['08031234567', '+2348031234567', '2348031234567', '+234 803 123 4567', '0803-123-4567'] as $search) {
        expect(Person::query()->whereBlind('phone', 'phone_bidx', (string) PhoneNumber::normalise($search))->pluck('id')->all())
            ->toBe([$person->id], "{$written} found by {$search}");
    }
})->with(['08031234567', '+2348031234567', '2348031234567', '+234 (803) 123-4567', '+2340803 123 4567']);

test('phone normalisation rejects what is not a Nigerian number', function (string $input) {
    expect(PhoneNumber::normalise($input))->toBeNull();
})->with(['12345', '0803123456', '080312345678', '+44 20 7946 0958', '00000000000', 'abc']);

test('clearing a protected field removes its blind index', function () {
    $person = personWithProtectedFields();

    $person->fill(['nin' => null])->save();

    expect(DB::table('blind_indexes')->where('indexable_id', $person->id)->where('name', 'nin_bidx')->exists())->toBeFalse()
        ->and(DB::table('persons')->where('id', $person->id)->value('nin'))->toBeNull()
        ->and(Person::query()->whereBlind('nin', 'nin_bidx', '12345678901')->count())->toBe(0);
});

test('persons without a value get no blind index', function () {
    $person = Person::factory()->in(Lga::factory()->create())->create();

    expect(DB::table('blind_indexes')->where('indexable_id', $person->id)->count())->toBe(0);
});

test('the activity log records which protected fields changed, never their values', function () {
    $person = personWithProtectedFields();
    $person = Person::query()->findOrFail($person->id);
    $person->fill(['nin' => '98765432109', 'phone' => '07011112222', 'occupation' => 'Trader'])->save();
    $person->fill(['address' => null])->save();

    $entries = Activity::query()->where('subject_type', Person::class)->where('subject_id', $person->id)->orderBy('id')->get();
    $json = $entries->map(fn (Activity $activity) => json_encode([$activity->attribute_changes, $activity->properties, $activity->description]))->implode("\n");

    foreach ([...array_values(PLAIN), '98765432109', '+2347011112222', '7011112222'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    // Blind index values never reach the log either.
    foreach (DB::table('blind_indexes')->pluck('value') as $index) {
        expect($json)->not->toContain($index);
    }

    $protected = $entries->where('event', 'protected_fields_changed')->values();

    expect($protected)->toHaveCount(3)
        ->and($protected[0]->properties['fields'])->toBe(['date_of_birth', 'nin', 'phone', 'address'])
        ->and($protected[0]->description)->toBe('Protected fields recorded: date of birth, NIN, phone number, address')
        ->and($protected[1]->properties['fields'])->toBe(['nin', 'phone'])
        ->and($protected[1]->description)->toBe('Protected fields changed: NIN, phone number')
        ->and($protected[2]->properties['fields'])->toBe(['address']);

    // The ordinary update entry has the occupation, and nothing encrypted.
    $update = $entries->where('event', 'updated')->first();

    expect($update?->attribute_changes['attributes'] ?? null)->toBe(['occupation' => 'Trader']);
});

test('saving without changes to protected fields logs no protected change', function () {
    $person = Person::query()->findOrFail(personWithProtectedFields()->id);

    $person->fill(['occupation' => 'Farmer', 'nin' => '12345678901'])->save();

    expect(Activity::query()->where('event', 'protected_fields_changed')->count())->toBe(1);
});

test('a partially loaded person cannot be saved', function () {
    $person = personWithProtectedFields();
    $partial = Person::query()->select(['id', 'ulid', 'first_name', 'classification', 'state_id', 'lga_id', 'created_by'])->findOrFail($person->id);

    expect(fn () => $partial->fill(['first_name' => 'Changed'])->save())->toThrow(LogicException::class);
    expect(DB::table('persons')->where('id', $person->id)->value('nin'))->toStartWith('nacl:');
});

test('protected fields are hidden when a person is serialised', function () {
    $person = personWithProtectedFields();
    $json = json_encode($person->fresh()?->toArray());

    foreach (PLAIN as $column => $value) {
        expect($json)->not->toContain($value)->not->toContain("\"{$column}\"");
    }
});

test('search_name is lowercased, unaccented and whitespace-normalised, on create and update', function () {
    $person = Person::factory()->in(Lga::factory()->create())->named('  Adébáyọ̀ ', 'OKONKWO', " Chukwu\t Emeka ")->create();

    expect(DB::table('persons')->where('id', $person->id)->value('search_name'))->toBe('adebayo chukwu emeka okonkwo');

    $person->fill(['last_name' => 'Okafor'])->save();

    expect(DB::table('persons')->where('id', $person->id)->value('search_name'))->toBe('adebayo chukwu emeka okafor');
});

beforeEach(function () {
    app(JurisdictionContext::class)->system();
});
