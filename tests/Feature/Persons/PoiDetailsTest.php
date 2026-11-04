<?php

use App\Enums\GrantScope;
use App\Http\Presenters\PoiPresenter;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\Religion;
use App\Models\State;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11G.4: person-of-interest details. Each field validates; origin LGA
| belongs to the origin state; age is computed; religion and political
| affiliations are encrypted with no blind index and appear only on the
| person's page (never in lists, search, exports or PDFs); only `full`
| grants show the details.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
    $this->person = Person::factory()->in($this->lga)->named('Tunde', 'Sensitive')->create(['date_of_birth' => '1990-06-15']);
    $this->poi = PersonOfInterest::factory()->forPerson($this->person)->create();
    $this->religion = Religion::query()->firstOrCreate(['code' => 'test_faith'], ['name' => 'Test Faith', 'is_active' => true, 'sort_order' => 99]);
});

/** @param array<string, mixed> $values */
function poiPayload(array $values = []): array
{
    return [
        'offence_categories' => [],
        'known_offences' => '<p>Suspected of kidnapping.</p>',
        ...$values,
    ];
}

function savePoi(array $values)
{
    return CrossFixtures::signIn(test()->sd)->put('/persons-of-interest/'.test()->poi->ulid, poiPayload($values));
}

test('every new field is saved, and each validates', function () {
    $origin = $this->world->lgas['A2'];

    savePoi([
        'criminal_record' => '<p>Convicted of robbery in 2015; three years in Kirikiri.</p>',
        'height_cm' => 178,
        'origin_state' => $origin->state->ulid,
        'origin_lga' => $origin->ulid,
        'hometown' => 'Ilesa',
        'marital_status' => 'married',
        'religion' => 'test_faith',
        'political_affiliations' => 'Youth wing of a party',
        'hair_colour' => 'black',
        'eye_colour' => 'dark_brown',
        'has_tribal_marks' => 'yes',
        'tribal_marks_description' => 'Three vertical marks on each cheek',
    ])->assertSessionHasNoErrors();

    $poi = $this->poi->fresh();
    expect($poi->height_cm)->toBe(178)
        ->and($poi->origin_lga_id)->toBe($origin->id)
        ->and($poi->origin_state_id)->toBe($origin->state_id)
        ->and($poi->marital_status?->value)->toBe('married')
        ->and($poi->religion)->toBe('test_faith')
        ->and($poi->has_tribal_marks?->value)->toBe('yes')
        ->and($poi->criminal_record_text)->toContain('Kirikiri');

    foreach ([
        ['height_cm' => 30], ['height_cm' => 260], ['marital_status' => 'engaged'], ['hair_colour' => 'blue'],
        ['eye_colour' => 'purple'], ['has_tribal_marks' => 'maybe'], ['religion' => 'not_a_code'],
        ['hometown' => str_repeat('h', 256)], ['tribal_marks_description' => str_repeat('t', 501)],
    ] as $bad) {
        savePoi($bad)->assertSessionHasErrors(array_keys($bad)[0]);
    }
});

test('the height may be given in feet and inches', function () {
    savePoi(['height_feet' => 5, 'height_inches' => 10])->assertSessionHasNoErrors();

    expect($this->poi->fresh()->height_cm)->toBe(178)
        ->and(PoiPresenter::heightLabel(178))->toBe('178 cm (5 ft 10 in)');
});

test('the LGA of origin must belong to the state of origin', function () {
    savePoi(['origin_state' => $this->world->states['B1']->ulid, 'origin_lga' => $this->lga->ulid])->assertSessionHasErrors('origin_lga');
    savePoi(['origin_lga' => $this->lga->ulid])->assertSessionHasErrors('origin_lga');
    // And in the database (composite foreign key).
    expect(fn () => DB::transaction(fn () => DB::table('persons_of_interest')->where('id', $this->poi->id)->update([
        'origin_state_id' => $this->world->states['B1']->id, 'origin_lga_id' => $this->lga->id,
    ])))->toThrow(QueryException::class);
});

test('origin never changes who can see the record', function () {
    $other = $this->world->lgas['B1'];
    savePoi(['origin_state' => $other->state->ulid, 'origin_lga' => $other->ulid])->assertSessionHasNoErrors();

    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get("/persons/{$this->person->ulid}")->assertNotFound();
    expect($this->poi->fresh()->lga_id)->toBe($this->lga->id);
});

test('age comes from the date of birth, else the approximate age', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04'));
    expect(PoiPresenter::age($this->person->fresh()))->toBe(36);

    $other = Person::factory()->in($this->lga)->create(['date_of_birth' => null, 'approximate_age' => 42]);
    expect(PoiPresenter::age($other))->toBe(42);
});

test('religion and political affiliations are encrypted with no blind index, and never logged', function () {
    savePoi(['religion' => 'test_faith', 'political_affiliations' => 'Youth wing of a party'])->assertSessionHasNoErrors();

    $row = DB::table('persons_of_interest')->where('id', $this->poi->id)->first();
    expect($row->religion)->toStartWith('nacl:')->not->toContain('test_faith')
        ->and($row->political_affiliations)->toStartWith('nacl:')->not->toContain('Youth wing')
        ->and(DB::table('blind_indexes')->where('indexable_type', $this->poi->getMorphClass())->count())->toBe(0)
        ->and(PersonOfInterest::BLIND_INDEXES)->toBe([]);

    // The POI's own entries (the test's Religion row is logged on its own).
    $log = Activity::query()->where('subject_type', $this->poi->getMorphClass())->get()->map(fn ($a) => json_encode([$a->attribute_changes, $a->properties, $a->description]))->implode('');
    expect($log)->not->toContain('test_faith')->not->toContain('Youth wing')
        ->and(Activity::query()->where('event', 'sensitive_details_changed')->where('subject_id', $this->poi->id)->first()?->properties['fields'])->toBe(['religion', 'political_affiliations']);
});

test('the person page shows the details; sensitive ones only in their own card', function () {
    savePoi(['religion' => 'test_faith', 'political_affiliations' => 'Youth wing', 'hair_colour' => 'grey', 'height_cm' => 170])->assertSessionHasNoErrors();

    $poi = CrossFixtures::signIn($this->sd)->get("/persons/{$this->person->ulid}?tab=poi")->assertOk()->inertiaProps('poi');
    expect($poi['sensitive'])->toBe(['religion' => ['value' => 'test_faith', 'label' => 'Test Faith'], 'political_affiliations' => 'Youth wing'])
        ->and($poi['hair_colour']['label'])->toBe('Grey')
        ->and($poi['height_label'])->toBe('170 cm (5 ft 7 in)')
        ->and($poi)->not->toHaveKey('religion');
});

test('special categories never reach lists, search, exports or PDFs', function () {
    savePoi(['religion' => 'test_faith', 'political_affiliations' => 'Youth wing of a party', 'has_tribal_marks' => 'yes'])->assertSessionHasNoErrors();
    $secrets = ['test_faith', 'Test Faith', 'Youth wing'];

    // The list's rows (its filter options carry the religions lookup itself, not anyone's value).
    $list = json_encode(CrossFixtures::signIn($this->sd)->get('/persons-of-interest')->inertiaProps('results'));
    $search = json_encode(CrossFixtures::signIn($this->sd)->getJson('/search/quick?q='.urlencode('Youth wing'))->json());
    $rows = app(JurisdictionContext::class)->actingAs($this->sd, function () {
        $exporter = ListExporters::find('persons_of_interest');

        return json_encode(array_map(fn ($c) => $c->key, $exporter->columns()));
    });

    foreach ($secrets as $secret) {
        expect($list)->not->toContain($secret)
            ->and($rows)->not->toContain('religion')->not->toContain('political');
    }

    // Searching for the affiliation never finds the person (not indexed).
    expect($search)->not->toContain($this->person->ulid);

    // The single-person PDF data: tribal marks yes, special categories no.
    $detail = PoiPresenter::withoutSensitive(PoiPresenter::detail($this->poi->fresh()));
    expect($detail)->not->toHaveKey('sensitive')
        ->and($detail['has_tribal_marks']['value'])->toBe('yes')
        ->and(json_encode($detail))->not->toContain('test_faith')->not->toContain('Youth wing');
});

test('only a full grant shows the POI details', function () {
    $owner = CrossFixtures::directorOfB1($this->world);
    $person = CrossFixtures::person($this->world->lgas['B1']);
    $poi = PersonOfInterest::factory()->forPerson($person)->create();
    $poi->forceFill(['hometown' => 'Kano city'])->save();
    $chief = $this->world->users['lga_chief'];

    CrossFixtures::grant($chief, $owner, GrantScope::Profile);
    expect(CrossFixtures::signIn($chief)->get("/persons/{$person->ulid}")->inertiaProps('tabs'))->not->toContain('poi');
});
