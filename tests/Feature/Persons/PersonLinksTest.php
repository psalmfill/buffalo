<?php

use App\Enums\Classification;
use App\Enums\PersonRoleType;
use App\Models\Community;
use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\OrganizationSector;
use App\Models\Person;
use App\Models\PersonAssociation;
use App\Models\PropertyType;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\ReferenceUsageRegistry;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

/*
|--------------------------------------------------------------------------
| Associations
|--------------------------------------------------------------------------
*/

test('an association is stored once and shown from both sides, inverted where directional', function () {
    $world = JurisdictionWorld::build();
    $worker = Person::factory()->in($world->lgas['A1a'])->named('Ade', 'Worker')->create();
    $boss = Person::factory()->in($world->lgas['A1a'])->named('Bola', 'Boss')->create();
    $chief = $world->users['lga_chief'];

    // "Bola Boss is Ade Worker's employer".
    $this->actingAs($chief)
        ->post("/persons/{$worker->ulid}/associations", ['person' => $boss->ulid, 'relationship' => 'employer', 'notes' => 'Runs the jetty'])
        ->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect(PersonAssociation::query()->count())->toBe(1);

    $this->actingAs($chief)->get("/persons/{$worker->ulid}?tab=associations")->assertInertia(fn (Assert $page) => $page
        ->has('associations', 1)
        ->where('associations.0.person.ulid', $boss->ulid)
        ->where('associations.0.relationship.value', 'employer')
        ->where('associations.0.notes', 'Runs the jetty'));

    $this->actingAs($chief)->get("/persons/{$boss->ulid}?tab=associations")->assertInertia(fn (Assert $page) => $page
        ->has('associations', 1)
        ->where('associations.0.person.ulid', $worker->ulid)
        ->where('associations.0.relationship.value', 'employee'));

    // Both persons' histories record it.
    expect(Activity::query()->where('event', 'association_added')->pluck('subject_id')->sort()->values()->all())
        ->toBe(collect([$worker->id, $boss->id])->sort()->values()->all());
});

test('no self-association and no duplicate pair, whichever way round', function () {
    $world = JurisdictionWorld::build();
    $a = Person::factory()->in($world->lgas['A1a'])->create();
    $b = Person::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/persons/{$a->ulid}/associations", ['person' => $a->ulid, 'relationship' => 'associate'])->assertSessionHasErrors(['person' => 'A person cannot be associated with themselves.']);
    $this->actingAs($chief)->post("/persons/{$a->ulid}/associations", ['person' => $b->ulid, 'relationship' => 'rival'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post("/persons/{$b->ulid}/associations", ['person' => $a->ulid, 'relationship' => 'family'])->assertSessionHasErrors(['person' => 'These two persons are already associated.']);

    PersonFixtures::system();
    expect(PersonAssociation::query()->count())->toBe(1);

    // The database enforces both rules too.
    expect(fn () => PersonAssociation::query()->forceCreate(['ulid' => (string) str()->ulid(), 'person_id' => $b->id, 'associated_person_id' => $a->id, 'relationship' => 'family']))
        ->toThrow(QueryException::class)
        ->and(fn () => PersonAssociation::query()->forceCreate(['ulid' => (string) str()->ulid(), 'person_id' => $a->id, 'associated_person_id' => $a->id, 'relationship' => 'family']))
        ->toThrow(QueryException::class);
});

test('both persons must be visible to link them', function (Closure $makeOther) {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $other = $makeOther($world);

    $this->actingAs($world->users['lga_chief'])
        ->post("/persons/{$person->ulid}/associations", ['person' => $other->ulid, 'relationship' => 'associate'])
        ->assertSessionHasErrors(['person' => 'Choose a person you can view.']);

    PersonFixtures::system();
    expect(PersonAssociation::query()->count())->toBe(0);
})->with([
    'other LGA' => [fn ($world) => Person::factory()->in($world->lgas['A1b'])->create()],
    'other state' => [fn ($world) => Person::factory()->in($world->lgas['B1'])->create()],
    'above clearance' => [fn ($world) => Person::factory()->in($world->lgas['A1a'])->classified(Classification::Confidential)->create()],
]);

test('an association with a person the viewer cannot see is not shown and cannot be removed by them', function () {
    $world = JurisdictionWorld::build();
    $visible = Person::factory()->in($world->lgas['A1a'])->create();
    $hidden = Person::factory()->in($world->lgas['A1b'])->create();
    $this->actingAs($world->users['state_director'])
        ->post("/persons/{$visible->ulid}/associations", ['person' => $hidden->ulid, 'relationship' => 'accomplice'])
        ->assertSessionHasNoErrors();
    PersonFixtures::system();
    $link = PersonAssociation::query()->sole();
    $this->flushSession();

    $this->actingAs($world->users['lga_chief'])->get("/persons/{$visible->ulid}?tab=associations")
        ->assertInertia(fn (Assert $page) => $page->has('associations', 0));
    $this->actingAs($world->users['lga_chief'])->delete("/persons/{$visible->ulid}/associations/{$link->ulid}")->assertNotFound();

    PersonFixtures::system();
    expect($link->fresh()?->trashed())->toBeFalse();
});

test('removing an association soft-deletes it for both sides', function () {
    $world = JurisdictionWorld::build();
    $a = Person::factory()->in($world->lgas['A1a'])->create();
    $b = Person::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];
    $this->actingAs($chief)->post("/persons/{$a->ulid}/associations", ['person' => $b->ulid, 'relationship' => 'associate']);
    PersonFixtures::system();
    $link = PersonAssociation::query()->sole();

    // Removed from the other person's page.
    $this->actingAs($chief)->delete("/persons/{$b->ulid}/associations/{$link->ulid}")->assertRedirect();

    PersonFixtures::system();
    expect(PersonAssociation::query()->count())->toBe(0)
        ->and(PersonAssociation::withTrashed()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'association_removed')->count())->toBe(2);

    // Once removed, the pair may be linked again.
    $this->actingAs($chief)->post("/persons/{$a->ulid}/associations", ['person' => $b->ulid, 'relationship' => 'family'])->assertSessionHasNoErrors();
});

/*
|--------------------------------------------------------------------------
| Traditional rulers and religious leaders (links from Phase 2)
|--------------------------------------------------------------------------
*/

function rulerCommunityPayload(Community $community, array $overrides = []): array
{
    return [
        'name' => $community->name,
        'other_names' => [],
        'type' => $community->type->value,
        'ward' => null,
        'latitude' => null,
        'longitude' => null,
        'population_estimate' => null,
        'notes' => null,
        ...$overrides,
    ];
}

test('a traditional ruler must hold the traditional ruler role and be visible', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $ruler = Person::factory()->in($world->lgas['A1a'])->withRole(PersonRoleType::TraditionalRuler)->create();
    $notRuler = Person::factory()->in($world->lgas['A1a'])->withRole(PersonRoleType::ReligiousLeader)->create();
    $formerRuler = Person::factory()->in($world->lgas['A1a'])->withRole(PersonRoleType::TraditionalRuler, active: false)->create();
    $elsewhere = Person::factory()->in($world->lgas['A1b'])->withRole(PersonRoleType::TraditionalRuler)->create();
    $chief = $world->users['lga_chief'];
    $url = "/communities/{$community->ulid}";

    $this->actingAs($chief)->put($url, rulerCommunityPayload($community, ['traditional_ruler' => $notRuler->ulid]))
        ->assertSessionHasErrors(['traditional_ruler' => 'The person must hold the traditional ruler role.']);
    $this->actingAs($chief)->put($url, rulerCommunityPayload($community, ['traditional_ruler' => $formerRuler->ulid]))->assertSessionHasErrors('traditional_ruler');
    $this->actingAs($chief)->put($url, rulerCommunityPayload($community, ['traditional_ruler' => $elsewhere->ulid]))
        ->assertSessionHasErrors(['traditional_ruler' => 'Choose a person you can view.']);
    $this->actingAs($chief)->put($url, rulerCommunityPayload($community, ['traditional_ruler' => $ruler->ulid]))->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect($community->fresh()?->traditional_ruler_id)->toBe($ruler->id)
        ->and(Activity::query()->where('event', 'traditional_ruler_changed')->sole()->properties->only(['old_person', 'new_person'])->all())
        ->toBe(['old_person' => null, 'new_person' => $ruler->ulid]);

    $this->actingAs($chief)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('community.traditional_ruler.person.ulid', $ruler->ulid)
        ->where('community.traditional_ruler.hidden', false));
});

test('a ruler the editor cannot see is shown as hidden and kept when the form omits it', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $secretRuler = Person::factory()->in($world->lgas['A1a'])->classified(Classification::Secret)->withRole(PersonRoleType::TraditionalRuler)->create();
    $community->forceFill(['traditional_ruler_id' => $secretRuler->id])->save();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->get("/communities/{$community->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('community.traditional_ruler.person', null)
        ->where('community.traditional_ruler.hidden', true));

    $this->actingAs($chief)->put("/communities/{$community->ulid}", rulerCommunityPayload($community, ['notes' => 'Edited']))->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect($community->fresh()?->traditional_ruler_id)->toBe($secretRuler->id)
        ->and($community->fresh()?->notes)->toBe('Edited');

    // Explicitly cleared: removed.
    $this->actingAs($chief)->put("/communities/{$community->ulid}", rulerCommunityPayload($community, ['traditional_ruler' => null]))->assertSessionHasNoErrors();
    PersonFixtures::system();
    expect($community->fresh()?->traditional_ruler_id)->toBeNull()
        ->and(Activity::query()->where('event', 'traditional_ruler_changed')->sole()->properties['old_person'])->toBe('not visible');
});

test('a religious institution leader must hold the religious leader role', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $institution = ReligiousInstitution::factory()->in($community)->create();
    $leader = Person::factory()->in($world->lgas['A1a'])->withRole(PersonRoleType::ReligiousLeader)->create();
    $notLeader = Person::factory()->in($world->lgas['A1a'])->withRole(PersonRoleType::TraditionalRuler)->create();
    $chief = $world->users['lga_chief'];

    PersonFixtures::system();
    $institution->load('religion');
    $payload = fn (array $overrides) => [
        'community' => $community->ulid,
        'religion' => $institution->religion->ulid,
        'denomination' => null,
        'name' => $institution->name,
        'type' => $institution->type->value,
        'estimated_congregation' => null,
        'latitude' => null,
        'longitude' => null,
        'notes' => null,
        ...$overrides,
    ];

    $this->actingAs($chief)->put("/religious-institutions/{$institution->ulid}", $payload(['leader' => $notLeader->ulid]))
        ->assertSessionHasErrors(['leader' => 'The person must hold the religious leader role.']);
    $this->actingAs($chief)->put("/religious-institutions/{$institution->ulid}", $payload(['leader' => $leader->ulid]))->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect($institution->fresh()?->leader_person_id)->toBe($leader->id)
        ->and(Activity::query()->where('event', 'leader_changed')->count())->toBe(1);
});

test('the person picker returns only visible persons, optionally with a role', function () {
    $world = JurisdictionWorld::build();
    $ruler = Person::factory()->in($world->lgas['A1a'])->named('Adewale', 'Ogunleye')->withRole(PersonRoleType::TraditionalRuler)->create();
    Person::factory()->in($world->lgas['A1a'])->named('Adewale', 'Ogunlade')->create();
    Person::factory()->in($world->lgas['B1'])->named('Adewale', 'Ogunleye')->withRole(PersonRoleType::TraditionalRuler)->create();
    $chief = $world->users['lga_chief'];

    $all = $this->actingAs($chief)->postJson('/persons/picker', ['q' => 'Adewale Ogunleye'])->assertOk()->json('results');
    $rulers = $this->actingAs($chief)->postJson('/persons/picker', ['q' => 'Adewale Ogunleye', 'role' => 'traditional_ruler'])->json('results');

    expect(collect($all)->pluck('name')->all())->toBe(['Adewale Ogunleye', 'Adewale Ogunlade'])
        ->and(collect($rulers)->pluck('ulid')->all())->toBe([$ruler->ulid])
        ->and(json_encode($all))->not->toContain('"id"');

    $this->actingAs($chief)->getJson('/persons/picker?q=Adewale')->assertMethodNotAllowed();
});

/*
|--------------------------------------------------------------------------
| Reference usage (Phase 2 open issue)
|--------------------------------------------------------------------------
*/

test('religions and denominations are in use once an institution references them, in any jurisdiction', function () {
    $world = JurisdictionWorld::build();
    $usage = app(ReferenceUsage::class);
    $religion = Religion::query()->create(['name' => 'Test religion', 'code' => 'test_religion']);
    $denomination = Denomination::query()->create(['name' => 'Test denomination', 'code' => 'test_denomination', 'religion_id' => $religion->id]);
    $unused = Religion::query()->create(['name' => 'Unused', 'code' => 'unused_religion']);

    expect($usage)->toBeInstanceOf(ReferenceUsageRegistry::class)
        ->and($usage->isInUse($religion))->toBeFalse()
        ->and($usage->isInUse($denomination))->toBeFalse();

    $institution = ReligiousInstitution::factory()->in(Community::factory()->in($world->lgas['B1'])->create())->create([
        'religion_id' => $religion->id,
        'denomination_id' => $denomination->id,
    ]);

    expect($usage->isInUse($religion))->toBeTrue()
        ->and($usage->isInUse($denomination))->toBeTrue()
        ->and($usage->isInUse($unused))->toBeFalse();

    // Soft-deleted records still count (they can be restored).
    $institution->delete();
    expect($usage->isInUse($religion))->toBeTrue();
});

test('modules register their own usage checks', function () {
    $registry = new ReferenceUsageRegistry;
    $religion = Religion::query()->create(['name' => 'Registered', 'code' => 'registered_religion']);

    expect($registry->isInUse($religion))->toBeFalse()
        ->and($registry->lookups())->toBe([]);

    $registry->register(Religion::class, fn ($value) => $value->is($religion));

    expect($registry->isInUse($religion))->toBeTrue()
        ->and($registry->lookups())->toBe([Religion::class])
        ->and(app(ReferenceUsageRegistry::class)->lookups())->toEqualCanonicalizing([Religion::class, Denomination::class, ThreatType::class, SecurityAgency::class, OrganizationSector::class, IncidentType::class, PropertyType::class]);
});

beforeEach(function () {
    PersonFixtures::system();
});
