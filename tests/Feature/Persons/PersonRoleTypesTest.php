<?php

use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
use App\Models\User;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

/*
| Phase 11A.1: person role types are an administrator-managed lookup.
*/

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    PersonFixtures::system();
});

function roleCodes(Person $person): array
{
    return PersonRole::query()->where('person_id', $person->id)->where('is_active', true)->get()->map(fn (PersonRole $r) => $r->typeCode())->sort()->values()->all();
}

test('the five system types and three examples are seeded', function () {
    expect(PersonRoleType::query()->orderBy('sort_order')->pluck('code')->all())
        ->toBe(['political_leader', 'traditional_ruler', 'religious_leader', 'community_leader', 'youth_leader', 'women_leader', 'market_leader', 'person_of_interest'])
        ->and(PersonRoleType::query()->where('is_system', true)->orderBy('code')->pluck('code')->all())->toBe(collect(PersonRoleType::SYSTEM)->sort()->values()->all());
});

test('an admin adds a role type; it appears in person forms, can be assigned and shows on the LGA Leadership tab', function () {
    $admin = User::factory()->superAdmin()->create();
    CrossFixtures::signIn($admin)->post('/reference-data/person-role-types', ['name' => 'Okada riders leader', 'code' => 'okada_leader', 'description' => null, 'sort_order' => 55])
        ->assertSessionHasNoErrors();

    $form = CrossFixtures::signIn($this->chief)->get('/persons/create')->viewData('page')['props'];
    expect(collect($form['options']['roles'])->pluck('value'))->toContain('okada_leader')
        ->and(collect($form['options']['roles'])->pluck('value'))->not->toContain(PersonRoleType::PERSON_OF_INTEREST);

    CrossFixtures::signIn($this->chief)->post('/persons', PersonFixtures::payload($this->lga, ['roles' => [['role' => 'okada_leader']], 'confirm_new' => true]))
        ->assertSessionHasNoErrors();
    PersonFixtures::system();
    $person = Person::query()->latest('id')->firstOrFail();
    expect(roleCodes($person))->toBe(['okada_leader']);

    CrossFixtures::signIn($this->chief)->get("/lgas/{$this->lga->ulid}?tab=leadership")->assertInertia(fn (Assert $page) => $page
        ->where('leadership.leadersByRole', fn ($groups) => collect($groups)->pluck('type.label')->contains('Okada riders leader')));

    // The history names the type (numeric ids are never shown).
    expect(DB::table('activity_log')->where('subject_type', PersonRole::class)->latest('id')->value('description'))->toBe('Role added: Okada riders leader');
});

test('system types can be renamed but never deactivated, and no code changes', function () {
    $admin = User::factory()->superAdmin()->create();
    $type = PersonRoleType::query()->where('code', PersonRoleType::COMMUNITY_LEADER)->firstOrFail();

    CrossFixtures::signIn($admin)->put("/reference-data/person-role-types/{$type->ulid}", ['name' => 'Community head', 'description' => null, 'sort_order' => 40])
        ->assertSessionHasNoErrors();
    expect($type->fresh()?->name)->toBe('Community head');

    CrossFixtures::signIn($admin)->post("/reference-data/person-role-types/{$type->ulid}/deactivate")
        ->assertSessionHasErrors('is_active');
    CrossFixtures::signIn($admin)->put("/reference-data/person-role-types/{$type->ulid}", ['name' => 'Community head', 'code' => 'renamed'])
        ->assertSessionHasErrors('code');

    expect($type->fresh()?->is_active)->toBeTrue()
        ->and($type->fresh()?->code)->toBe(PersonRoleType::COMMUNITY_LEADER)
        // Behind the guards: the database refuses too.
        ->and(fn () => DB::transaction(fn () => DB::table('person_role_types')->where('id', $type->id)->update(['is_active' => false])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('person_role_types')->where('id', $type->id)->update(['code' => 'renamed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('person_role_types')->where('id', $type->id)->delete()))->toThrow(QueryException::class);

    // A renamed system type keeps its behaviour (by code).
    $ruler = Person::factory()->in($this->lga)->withRole(PersonRoleType::TRADITIONAL_RULER)->create();
    expect($ruler->holdsRole(PersonRoleType::TRADITIONAL_RULER))->toBeTrue();
});

test('a deactivated type stays on persons who hold it but cannot be newly assigned', function () {
    $admin = User::factory()->superAdmin()->create();
    $type = PersonRoleType::query()->where('code', 'youth_leader')->firstOrFail();
    $holder = Person::factory()->in($this->lga)->withRole('youth_leader')->create();

    CrossFixtures::signIn($admin)->post("/reference-data/person-role-types/{$type->ulid}/deactivate")->assertSessionHasNoErrors();

    $form = CrossFixtures::signIn($this->chief)->get('/persons/create')->viewData('page')['props'];
    expect(collect($form['options']['roles'])->pluck('value'))->not->toContain('youth_leader');

    // Not on a new person...
    CrossFixtures::signIn($this->chief)->post('/persons', PersonFixtures::payload($this->lga, ['roles' => [['role' => 'youth_leader']], 'confirm_new' => true]))
        ->assertSessionHasErrors('roles.0.role');

    // ...but its holder keeps it, and it is still shown.
    $payload = PersonFixtures::payload($this->lga, ['lga' => null, 'confirm_new' => null, 'first_name' => $holder->first_name, 'last_name' => $holder->last_name, 'roles' => [['role' => 'youth_leader']]]);
    CrossFixtures::signIn($this->world->users['state_director'])->put("/persons/{$holder->ulid}", $payload)->assertSessionHasNoErrors();
    PersonFixtures::system();
    expect(roleCodes($holder))->toBe(['youth_leader']);

    CrossFixtures::signIn($this->world->users['state_director'])->get("/persons/{$holder->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('person.all_roles.0.role.value', 'youth_leader')
        ->where('person.all_roles.0.type_active', false));

    // Once dropped, it cannot be switched back on.
    CrossFixtures::signIn($this->world->users['state_director'])->put("/persons/{$holder->ulid}", [...$payload, 'roles' => []])->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->world->users['state_director'])->put("/persons/{$holder->ulid}", $payload)->assertSessionHasErrors('roles.0.role');
});

test('the person of interest type is never offered or set through the persons forms', function () {
    CrossFixtures::signIn($this->chief)->post('/persons', PersonFixtures::payload($this->lga, ['roles' => [['role' => PersonRoleType::PERSON_OF_INTEREST]], 'confirm_new' => true]))
        ->assertSessionHasErrors(['roles.0.role' => 'The person of interest role is managed in the persons of interest module.']);
});

test('role types in use are reported as such and the person search filters by any type', function () {
    $type = PersonRoleType::query()->where('code', 'market_leader')->firstOrFail();
    expect(app(ReferenceUsage::class)->isInUse($type))->toBeFalse();

    $person = Person::factory()->in($this->lga)->named('Iya', 'Oloja')->withRole('market_leader')->create();
    expect(app(ReferenceUsage::class)->isInUse($type))->toBeTrue();

    CrossFixtures::signIn($this->world->users['state_director'])->post('/persons/search', ['role' => 'market_leader'])
        ->assertInertia(fn (Assert $page) => $page->where('results', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$person->ulid]));
});
