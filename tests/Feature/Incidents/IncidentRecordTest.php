<?php

use App\Enums\Classification;
use App\Models\AgencyFormation;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentChild;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PropertyType;
use App\Models\RecordAccessLog;
use App\Models\SecurityAgency;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
});

/**
 * An incident with one row in every child table, in $lga.
 */
function incidentWithChildren(Lga $lga, array $attributes = []): Incident
{
    CrossFixtures::system();
    $incident = Incident::factory()->in($lga)->create($attributes);
    $agency = SecurityAgency::factory()->create();
    $place = ['incident_id' => $incident->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id];

    IncidentCasualty::query()->forceCreate([...$place, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 2]);
    IncidentPerson::query()->forceCreate([...$place, 'unidentified_description' => 'Tall man in a red cap', 'involvement' => 'suspect']);
    IncidentCriminalGroup::query()->forceCreate([...$place, 'criminal_group_id' => CriminalGroup::factory()->in($lga)->create()->id, 'role' => 'suspected']);
    IncidentAgency::query()->forceCreate([...$place, 'security_agency_id' => $agency->id, 'role' => 'first_responder']);
    IncidentProperty::query()->forceCreate([...$place, 'property_type_id' => PropertyType::factory()->create()->id, 'quantity' => 1]);

    return $incident;
}

/** @return list<class-string<IncidentChild>> */
function incidentChildClasses(): array
{
    return [IncidentCasualty::class, IncidentPerson::class, IncidentCriminalGroup::class, IncidentAgency::class, IncidentProperty::class];
}

test('incidents and every child table follow the jurisdiction matrix', function (string $who) {
    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        incidentWithChildren($this->world->lgas[$lga], ['title' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);
    $user = $this->world->users[$who];

    app(JurisdictionContext::class)->actingAs($user, function () use ($expected) {
        expect(Incident::query()->orderBy('title')->pluck('title')->all())->toBe($expected);

        foreach (incidentChildClasses() as $class) {
            expect($class::query()->count())->toBe(count($expected), $class);
        }
    });
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('classification narrows incidents and their children; confidential and secret views are logged', function () {
    foreach (Classification::cases() as $level) {
        incidentWithChildren($this->world->lgas['A1a'], ['title' => $level->value, 'classification' => $level]);
    }

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => [
        Incident::query()->orderBy('title')->pluck('title')->all(),
        IncidentCasualty::query()->count(),
    ]);

    expect($visible('lga_chief'))->toBe([['restricted'], 1])
        ->and($visible('deputy_state_director'))->toBe([['confidential', 'restricted'], 2])
        ->and($visible('state_director'))->toBe([['confidential', 'restricted', 'secret'], 3])
        ->and($visible('super_admin'))->toBe([[], 0]);

    $restricted = Incident::query()->where('title', 'restricted')->sole();
    $secret = Incident::query()->where('title', 'secret')->sole();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/incidents/{$restricted->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/incidents/{$secret->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/incidents/{$secret->ulid}")->assertNotFound();

    $logged = fn (Incident $i) => RecordAccessLog::query()->where('subject_type', $i->getMorphClass())->where('subject_id', $i->id)->exists();
    expect($logged($restricted))->toBeFalse()->and($logged($secret))->toBeTrue();
});

test('a child row is not visible through its own place alone: the incident must be visible too', function () {
    // A child whose own place is within reach, on an incident the viewer
    // cannot see (secret): hidden.
    incidentWithChildren($this->world->lgas['A1a'], ['classification' => Classification::Secret]);

    app(JurisdictionContext::class)->actingAs($this->world->users['lga_chief'], function () {
        foreach (incidentChildClasses() as $class) {
            expect($class::query()->count())->toBe(0, $class);
        }
    });
});

test('children follow the incident when its LGA changes, and cannot disagree with it', function () {
    $incident = incidentWithChildren($this->world->lgas['A1a']);
    $b1 = $this->world->lgas['B1'];

    $incident->forceFill(['lga_id' => $b1->id, 'state_id' => $b1->state_id])->save();

    foreach (incidentChildClasses() as $class) {
        $places = $class::query()->withoutGlobalScopes()->where('incident_id', $incident->id)->get(['lga_id', 'state_id']);
        expect($places->pluck('lga_id')->unique()->all())->toBe([$b1->id], $class)
            ->and($places->pluck('state_id')->unique()->all())->toBe([$b1->state_id], $class);
    }

    expect(fn () => DB::transaction(fn () => DB::table('incident_casualties')->where('incident_id', $incident->id)->update(['lga_id' => $this->world->lgas['A1a']->id, 'state_id' => $this->world->lgas['A1a']->state_id])))
        ->toThrow(QueryException::class);
});

test('database rules for casualties, persons, agencies and properties', function () {
    $incident = incidentWithChildren($this->world->lgas['A1a']);
    $place = ['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id];
    $police = SecurityAgency::factory()->create();
    $army = SecurityAgency::factory()->create();
    $insert = fn (string $table, array $row) => fn () => DB::transaction(fn () => DB::table($table)->insert([...$place, 'ulid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(), ...$row]));

    // An agency only for security personnel, and required for them.
    expect($insert('incident_casualties', ['outcome' => 'injured', 'group' => 'civilian', 'security_agency_id' => $police->id, 'adult_male' => 1]))->toThrow(QueryException::class)
        ->and($insert('incident_casualties', ['outcome' => 'injured', 'group' => 'security_personnel', 'adult_male' => 1]))->toThrow(QueryException::class)
        // At least one count.
        ->and($insert('incident_casualties', ['outcome' => 'injured', 'group' => 'civilian']))->toThrow(QueryException::class)
        // One row per outcome, group and agency.
        ->and($insert('incident_casualties', ['outcome' => 'killed', 'group' => 'civilian', 'adult_female' => 1]))->toThrow(QueryException::class)
        // A person or a description.
        ->and($insert('incident_persons', ['involvement' => 'victim']))->toThrow(QueryException::class)
        // The formation must belong to the agency.
        ->and($insert('incident_agencies', ['security_agency_id' => $army->id, 'role' => 'supporting', 'agency_formation_id' => AgencyFormation::factory()->of($police)->in($this->world->lgas['A1a'])->create()->id]))->toThrow(QueryException::class)
        ->and($insert('incident_properties', ['property_type_id' => PropertyType::factory()->create()->id, 'quantity' => 0]))->toThrow(QueryException::class);

    $insert('incident_casualties', ['outcome' => 'injured', 'group' => 'security_personnel', 'security_agency_id' => $police->id, 'adult_male' => 1])();
    expect(IncidentCasualty::query()->where('incident_id', $incident->id)->count())->toBe(2);
});

test('incident types, agencies and property types used by incidents are in use', function () {
    $incident = incidentWithChildren($this->world->lgas['A1a']);
    $usage = app(ReferenceUsage::class);
    $casualtyAgency = SecurityAgency::factory()->create();
    IncidentCasualty::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'outcome' => 'injured', 'group' => 'security_personnel', 'security_agency_id' => $casualtyAgency->id, 'adult_male' => 1]);

    expect($usage->isInUse(IncidentType::query()->findOrFail($incident->incident_type_id)))->toBeTrue()
        ->and($usage->isInUse(IncidentAgency::query()->where('incident_id', $incident->id)->sole()->agency))->toBeTrue()
        ->and($usage->isInUse($casualtyAgency))->toBeTrue()
        ->and($usage->isInUse(IncidentProperty::query()->where('incident_id', $incident->id)->sole()->propertyType))->toBeTrue()
        ->and($usage->isInUse(IncidentType::factory()->create()))->toBeFalse()
        ->and($usage->isInUse(SecurityAgency::factory()->create()))->toBeFalse();
});

test('the full-text search vector covers the title and description', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->create([
        'title' => 'Kidnapping on the Abuja road',
        'description' => 'Gunmen abducted travellers near the toll gate.',
        'location_description' => 'Kaduna expressway junction',
    ]);

    $hits = fn (string $query) => DB::table('incidents')->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$query])->pluck('id')->all();

    expect($hits('kidnapping'))->toBe([$incident->id])
        ->and($hits('abducted travellers'))->toBe([$incident->id])
        ->and($hits('flood'))->toBe([]);

    expect(DB::selectOne("select indexdef from pg_indexes where tablename = 'incidents' and indexdef ilike '%gin%search_vector%'"))->not->toBeNull();
});

test('person links, groups and sites keep their own visibility', function () {
    $incident = incidentWithChildren($this->world->lgas['A1a']);
    $person = Person::factory()->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create();
    IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'person_id' => $person->id, 'involvement' => 'victim']);
    $site = OrganizationSite::factory()->of(Organization::factory()->create())->in($this->world->lgas['A1a'])->classified(Classification::Confidential)->create();
    IncidentProperty::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'property_type_id' => PropertyType::factory()->create()->id, 'organization_site_id' => $site->id]);

    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/incidents/{$incident->ulid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('persons.1.person', null)
            ->where('persons.1.person_hidden', true)
            ->where('properties.1.site', null)
            ->where('properties.1.site_hidden', true));

    CrossFixtures::signIn($this->world->users['state_director'])->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn ($page) => $page
            ->where('persons.1.person.ulid', $person->ulid)
            ->where('properties.1.site.ulid', $site->ulid));
});
