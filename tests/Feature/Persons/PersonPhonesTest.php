<?php

use App\Enums\GrantScope;
use App\Models\Person;
use App\Models\PersonPhone;
use App\Support\CrossJurisdiction\MatchCriteria;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\Merge\PersonDuplicateFinder;
use App\Support\Persons\PersonPhones;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\MergeFixtures;

/*
| Phase 11G.4: several phone numbers per person, each encrypted with a
| blind index; every number is matched (search, PersonMatchService,
| duplicates), numbers move with merges and back with undo, and only `full`
| grants show them.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
});

function withPhones(Person $person, array $numbers): Person
{
    PersonPhones::sync($person, array_map(fn (string $n, int $i) => ['number' => $n, 'label' => $i === 0 ? 'mobile' : 'work', 'is_primary' => $i === 0], $numbers, array_keys($numbers)), null);

    return $person;
}

test('the person form records several numbers, encrypted with blind indexes, one primary', function () {
    $person = Person::factory()->in($this->lga)->create(['created_by' => $this->chief->id]);

    CrossFixtures::signIn($this->chief)->put("/persons/{$person->ulid}", [
        'first_name' => $person->first_name, 'last_name' => $person->last_name, 'gender' => 'male', 'classification' => 'restricted',
        'phones' => [
            ['number' => '0803 111 2222', 'label' => 'mobile', 'is_primary' => false],
            ['number' => '+234 805 333 4444', 'label' => 'work', 'is_primary' => true],
        ],
    ])->assertSessionHasNoErrors();

    $rows = DB::table('person_phones')->where('person_id', $person->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('number')->every(fn ($n) => str_starts_with($n, 'nacl:') && ! str_contains($n, '1112222')))->toBeTrue()
        ->and(DB::table('blind_indexes')->where('indexable_type', (new PersonPhone)->getMorphClass())->whereIn('indexable_id', $rows->pluck('id'))->count())->toBe(2)
        ->and(PersonPhones::primary($person))->toBe('+2348053334444');

    // The number never reaches the activity log.
    $log = Activity::query()->get()->map(fn ($a) => json_encode([$a->attribute_changes, $a->properties]))->implode('');
    expect($log)->not->toContain('1112222')->not->toContain('3334444')
        ->and(Activity::query()->where('event', 'phone_numbers_changed')->where('subject_id', $person->id)->first()?->properties['added'])->toBe(2);

    // Invalid numbers are refused; the database refuses clear text.
    CrossFixtures::signIn($this->chief)->put("/persons/{$person->ulid}", [
        'first_name' => $person->first_name, 'last_name' => $person->last_name, 'gender' => 'male', 'classification' => 'restricted',
        'phones' => [['number' => '12345', 'label' => 'mobile', 'is_primary' => true]],
    ])->assertSessionHasErrors('phones.0.number');
    expect(fn () => DB::transaction(fn () => DB::table('person_phones')->where('person_id', $person->id)->limit(1)->update(['number' => '+2348031112222'])))->toThrow(QueryException::class);
});

test('removing a number from the form soft-deletes it; the rest stay', function () {
    $person = withPhones(Person::factory()->in($this->lga)->create(['created_by' => $this->chief->id]), ['+2348031110001', '+2348031110002']);

    PersonPhones::sync($person, [['number' => '+2348031110002', 'label' => 'mobile', 'is_primary' => true]], $this->chief);

    expect(PersonPhones::forPerson($person))->toHaveCount(1)
        ->and(PersonPhone::withTrashed()->where('person_id', $person->id)->count())->toBe(2)
        ->and(PersonPhones::personIdsWithNumber('+2348031110001')->count())->toBe(0);
});

test('the persons search and global search find a person by their second number', function () {
    $person = withPhones(Person::factory()->in($this->lga)->named('Second', 'Number')->create(), ['+2348031110001', '+2348057770002']);

    CrossFixtures::signIn($this->chief)->post('/persons/search', ['q' => '0805 777 0002'])
        ->assertInertia(fn (Assert $page) => $page->where('results.0.ulid', $person->ulid));

    $hits = CrossFixtures::signIn($this->chief)->getJson('/search/quick?q='.urlencode('08057770002'))->assertOk()->json();
    expect(json_encode($hits))->toContain($person->ulid);
});

test('PersonMatchService matches a new person\'s phone against all of a person\'s numbers', function () {
    $elsewhere = withPhones(CrossFixtures::person($this->world->lgas['B1'], ['nin' => null]), ['+2348031110001', '+2348059990003']);

    $result = CrossFixtures::match($this->chief, MatchCriteria::fromPersonInput(['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0805 999 0003']));

    expect($result->hits)->toHaveCount(1)
        ->and(json_encode($result->hitsArray()))->not->toContain($elsewhere->ulid); // hits never expose the record
});

test('the duplicate finder pairs persons sharing any number', function () {
    $a = withPhones(Person::factory()->in($this->lga)->named('First', 'Holder')->create(), ['+2348031110001', '+2348052220002']);
    $b = withPhones(Person::factory()->in($this->world->lgas['A1b'])->named('Other', 'Name')->create(), ['+2348052220002']);

    $pairs = app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(PersonDuplicateFinder::class)->forUser($this->sd));
    $pair = collect($pairs)->first(fn (array $p) => collect([$p['a']->id, $p['b']->id])->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all());

    expect($pair)->not->toBeNull()
        ->and($pair['signals'])->toContain('phone');
});

test('merging moves the source\'s numbers to the survivor; undo moves them back', function () {
    $survivor = withPhones(Person::factory()->in($this->lga)->named('Ade', 'Survivor')->create(), ['+2348031110001', '+2348031110002']);
    $source = withPhones(Person::factory()->in($this->lga)->named('Ade', 'Source')->create(), ['+2348031110002', '+2348041110003']);

    $merge = MergeFixtures::merge($this->sd, $survivor, $source);

    $numbers = fn (Person $p) => collect(PersonPhones::forPerson($p))->pluck('number')->sort()->values()->all();
    expect($numbers($survivor))->toBe(['+2348031110001', '+2348031110002', '+2348041110003'])
        ->and(PersonPhone::query()->where('person_id', $survivor->id)->where('is_primary', true)->count())->toBe(1)
        ->and(PersonPhones::personIdsWithNumber('+2348041110003')->pluck('person_id')->all())->toBe([$survivor->id]);

    MergeFixtures::undo($this->sd, $merge);

    expect($numbers($survivor))->toBe(['+2348031110001', '+2348031110002'])
        ->and(PersonPhone::query()->where('person_id', $source->id)->pluck('id')->count())->toBe(2)
        ->and(PersonPhones::personIdsWithNumber('+2348041110003')->pluck('person_id')->all())->toBe([$source->id]);
});

test('only a full grant shows the phone numbers', function () {
    $owner = CrossFixtures::directorOfB1($this->world);
    $person = withPhones(CrossFixtures::person($this->world->lgas['B1']), ['+2348031110001']);

    CrossFixtures::grant($this->chief, $owner, GrantScope::Profile);
    expect(CrossFixtures::signIn($this->chief)->get("/persons/{$person->ulid}")->inertiaProps('person'))->not->toHaveKey('phones');
});
