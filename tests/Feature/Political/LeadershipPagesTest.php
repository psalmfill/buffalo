<?php

use App\Enums\PersonRoleType;
use App\Enums\PoliticalOfficeType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\SenatorialDistrict;
use App\Models\Ward;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

beforeEach(fn () => PersonFixtures::system());

/**
 * A1a with a sibling LGA in the same district: a senator, a rep recorded
 * against the sibling, chairmen (one ended), a councillor, rulers and
 * leaders.
 */
function leadershipWorld(): array
{
    $world = JurisdictionWorld::build();
    $a1a = $world->lgas['A1a'];
    $sibling = Lga::factory()->create(['senatorial_district_id' => $a1a->senatorial_district_id, 'name' => 'Sibling LGA']);
    $district = SenatorialDistrict::query()->findOrFail($a1a->senatorial_district_id);
    $ward = Ward::factory()->create(['lga_id' => $a1a->id, 'name' => 'Ward One']);
    $person = fn (Lga $lga, string $first, string $last) => Person::factory()->in($lga)->named($first, $last)->create();

    PoliticalOffice::factory()->forPerson($person($a1a, 'Sade', 'Senator'))->senator($district)->create();
    PoliticalOffice::factory()->forPerson($person($a1a, 'Olu', 'OldSenator'))->senator($district)->ended()->create();
    PoliticalOffice::factory()->forPerson($person($sibling, 'Remi', 'Rep'))->inLga(PoliticalOfficeType::HouseOfRepsMember, $sibling)->create(['constituency_name' => 'Sibling Federal']);
    PoliticalOffice::factory()->forPerson($person($a1a, 'Chika', 'Chairman'))->inLga(PoliticalOfficeType::LgaChairman, $a1a)->create();
    PoliticalOffice::factory()->forPerson($person($a1a, 'Ex', 'Chairman'))->inLga(PoliticalOfficeType::LgaChairman, $a1a)->ended()->create();
    PoliticalOffice::factory()->forPerson($person($sibling, 'Sib', 'Chairman'))->inLga(PoliticalOfficeType::LgaChairman, $sibling)->create();
    PoliticalOffice::factory()->forPerson($person($a1a, 'Vivian', 'Vice'))->inLga(PoliticalOfficeType::LgaViceChairman, $a1a)->create();
    PoliticalOffice::factory()->forPerson($person($a1a, 'Kunle', 'Councillor'))->councillor($ward)->create();
    // Another district: never on this page.
    PoliticalOffice::factory()->forPerson($person($world->lgas['A1b'], 'Far', 'Senator'))->senator(SenatorialDistrict::query()->findOrFail($world->lgas['A1b']->senatorial_district_id))->create();

    $community = Community::factory()->in($a1a)->create(['name' => 'Oba Town']);
    $ruler = Person::factory()->in($a1a)->named('Oba', 'Ruler')->withRole(PersonRoleType::TraditionalRuler)->create();
    $community->forceFill(['traditional_ruler_id' => $ruler->id])->save();
    $imam = Person::factory()->in($a1a)->named('Imam', 'Leader')->withRole(PersonRoleType::ReligiousLeader)->create();
    ReligiousInstitution::factory()->in($community)->create(['name' => 'Central Mosque', 'leader_person_id' => $imam->id]);
    Person::factory()->in($a1a)->named('Comm', 'Leader')->withRole(PersonRoleType::CommunityLeader)->create();
    Person::factory()->in($a1a)->named('Former', 'Leader')->withRole(PersonRoleType::CommunityLeader, active: false)->create();

    return [$world, $district, $a1a, $sibling];
}

test('the district page shows current holders, prominent persons and past holders', function () {
    [$world, $district, $a1a, $sibling] = leadershipWorld();

    $this->actingAs($world->users['state_director'])->get("/senatorial-districts/{$district->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('senatorial-districts/show')
        ->where('currentHolders', fn ($holders) => collect($holders)->pluck('person.name')->all() === ['Sade Senator', 'Remi Rep', 'Chika Chairman', 'Sib Chairman'])
        ->where('pastHolders', fn ($holders) => collect($holders)->pluck('person.name')->sort()->values()->all() === ['Ex Chairman', 'Olu OldSenator'])
        ->where('lgas', fn ($lgas) => collect($lgas)->mapWithKeys(fn ($l) => [$l['name'] => collect($l['chairman'])->pluck('name')->all()])->sortKeys()->all()
            === collect([$a1a->name => ['Chika Chairman'], 'Sibling LGA' => ['Sib Chairman']])->sortKeys()->all())
        // Persons holding a leadership role (active) in the district's LGAs.
        ->where('prominentPersons', fn ($persons) => collect($persons)->pluck('name')->sort()->values()->all() === ['Comm Leader', 'Imam Leader', 'Oba Ruler']));
});

test('an LGA chief sees their district with their own LGA\'s holders only', function () {
    [$world, $district] = leadershipWorld();

    $this->actingAs($world->users['lga_chief'])->get('/senatorial-districts')->assertInertia(fn (Assert $page) => $page
        ->has('districts', 1)
        ->where('districts.0.ulid', $district->ulid)
        ->where('districts.0.senators.0.name', 'Sade Senator')
        ->where('districts.0.lga_count', 2));

    $this->actingAs($world->users['lga_chief'])->get("/senatorial-districts/{$district->ulid}")->assertInertia(fn (Assert $page) => $page
        // The senator (district rule) and their own chairman; not the sibling LGA's.
        ->where('currentHolders', fn ($holders) => collect($holders)->pluck('person.name')->all() === ['Sade Senator', 'Chika Chairman']));

    // Another district is 404.
    $other = SenatorialDistrict::query()->findOrFail($world->lgas['A1b']->senatorial_district_id);
    $this->actingAs($world->users['lga_chief'])->get("/senatorial-districts/{$other->ulid}")->assertNotFound();
});

test('districts are listed and bound within jurisdiction', function () {
    [$world, $district] = leadershipWorld();
    $kanoDistrict = SenatorialDistrict::query()->findOrFail($world->lgas['B1']->senatorial_district_id);

    $this->actingAs($world->users['state_director'])->get("/senatorial-districts/{$kanoDistrict->ulid}")->assertNotFound();
    $this->actingAs($world->users['state_director'])->get('/senatorial-districts')->assertInertia(fn (Assert $page) => $page
        ->where('districts', fn ($d) => ! collect($d)->pluck('ulid')->contains($kanoDistrict->ulid) && collect($d)->pluck('ulid')->contains($district->ulid)));
    $this->flushSession();
    $this->actingAs($world->users['super_admin'])->get('/senatorial-districts')->assertForbidden();
});

test('the LGA leadership tab shows current leaders and excludes ended offices', function () {
    [$world, , $a1a] = leadershipWorld();

    $this->actingAs($world->users['lga_chief'])->get("/lgas/{$a1a->ulid}?tab=leadership")->assertInertia(fn (Assert $page) => $page
        ->where('leadership.chairman', fn ($c) => collect($c)->pluck('person.name')->all() === ['Chika Chairman'])
        ->where('leadership.viceChairman.0.person.name', 'Vivian Vice')
        ->where('leadership.councillors.0.person.name', 'Kunle Councillor')
        ->where('leadership.councillors.0.ward.name', 'Ward One')
        ->where('leadership.traditionalRulers.0.person.name', 'Oba Ruler')
        ->where('leadership.traditionalRulers.0.community.name', 'Oba Town')
        ->where('leadership.religiousLeaders.0.person.name', 'Imam Leader')
        ->where('leadership.communityLeaders', fn ($l) => collect($l)->pluck('name')->all() === ['Comm Leader']));
});
