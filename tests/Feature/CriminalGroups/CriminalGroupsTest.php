<?php

use App\Enums\Classification;
use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Enums\RiskLevel;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

beforeEach(fn () => PersonFixtures::system());

function groupPayload(array $overrides = []): array
{
    return [
        'name' => 'Black Axe Waterside',
        'other_names' => ['Axe Boys'],
        'type' => 'cult',
        'description' => 'A cult active around the waterside.',
        'estimated_size' => 40,
        'status' => 'active',
        'classification' => 'restricted',
        ...$overrides,
    ];
}

test('groups follow the jurisdiction matrix', function (string $who) {
    $world = JurisdictionWorld::build();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        CriminalGroup::factory()->in($world->lgas[$lga])->create(['name' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    expect(app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => CriminalGroup::query()->orderBy('name')->pluck('name')->all()))
        ->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('groups are classified: levels above the viewer are invisible', function () {
    $world = JurisdictionWorld::build();

    foreach (Classification::cases() as $level) {
        CriminalGroup::factory()->in($world->lgas['A1a'])->classified($level)->create(['name' => $level->value]);
    }

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($world->users[$who], fn () => CriminalGroup::query()->orderBy('name')->pluck('name')->all());

    expect($visible('lga_chief'))->toBe(['restricted'])
        ->and($visible('deputy_state_director'))->toBe(['confidential', 'restricted'])
        ->and($visible('state_director'))->toBe(['confidential', 'restricted', 'secret']);

    $this->actingAs($world->users['lga_chief'])->post('/criminal-groups', groupPayload(['lga' => $world->lgas['A1a']->ulid, 'classification' => 'confidential']))
        ->assertSessionHasErrors(['classification' => 'You cannot classify a record above your own clearance.']);
});

test('areas of operation never grant visibility', function () {
    $world = JurisdictionWorld::build();
    // Home: A1b. Operates in A1b and A1a (the chief's LGA) and in another state.
    $group = CriminalGroup::factory()->in($world->lgas['A1b'])->create(['name' => 'Home elsewhere']);
    foreach (['A1b', 'A1a', 'B1'] as $place) {
        $area = new CriminalGroupArea;
        $area->forceFill(['criminal_group_id' => $group->id, 'lga_id' => $world->lgas[$place]->id, 'state_id' => $world->lgas[$place]->state_id])->save();
    }

    $chief = $world->users['lga_chief'];

    expect(app(JurisdictionContext::class)->actingAs($chief, fn () => [CriminalGroup::query()->count(), CriminalGroupArea::query()->count()]))->toBe([0, 0]);

    $this->actingAs($chief)->get("/criminal-groups/{$group->ulid}")->assertNotFound();
    $this->actingAs($chief)->get('/criminal-groups')->assertInertia(fn (Assert $page) => $page->has('groups.data', 0));

    // The home state's director sees the group with all its areas, other states included.
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->get("/criminal-groups/{$group->ulid}?tab=areas")->assertInertia(fn (Assert $page) => $page->has('areas', 3));
    // A zone-B DDG (where one area is) does not see it.
    $this->flushSession();
    $this->actingAs($world->users['ddg_zone_b'])->get("/criminal-groups/{$group->ulid}")->assertNotFound();
});

test('a user creates a group and adds areas anywhere, once each', function () {
    $world = JurisdictionWorld::build();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post('/criminal-groups', groupPayload(['lga' => $world->lgas['A1b']->ulid]))->assertSessionHasErrors('lga');
    $this->actingAs($chief)->post('/criminal-groups', groupPayload(['lga' => $world->lgas['A1a']->ulid]))->assertSessionHasNoErrors();

    PersonFixtures::system();
    $group = CriminalGroup::query()->sole();
    expect($group->lga_id)->toBe($world->lgas['A1a']->id)->and($group->other_names)->toBe(['Axe Boys']);

    $this->actingAs($chief)->post("/criminal-groups/{$group->ulid}/areas", ['lga' => $world->lgas['B1']->ulid, 'notes' => 'Kidnap route'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post("/criminal-groups/{$group->ulid}/areas", ['lga' => $world->lgas['B1']->ulid])->assertSessionHasErrors('lga');

    PersonFixtures::system();
    expect(CriminalGroupArea::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'area_added')->count())->toBe(1);

    // The home LGA is fixed.
    $this->actingAs($chief)->put("/criminal-groups/{$group->ulid}", groupPayload(['lga' => $world->lgas['A1a']->ulid]))->assertSessionHasErrors('lga');
});

test('members must be persons the user can see; each person once', function () {
    $world = JurisdictionWorld::build();
    $group = CriminalGroup::factory()->in($world->lgas['A1a'])->create();
    $visible = Person::factory()->in($world->lgas['A1a'])->create();
    $otherLga = Person::factory()->in($world->lgas['A1b'])->create();
    $classified = Person::factory()->in($world->lgas['A1a'])->classified(Classification::Confidential)->create();
    $chief = $world->users['lga_chief'];
    $add = fn (Person $p) => $this->actingAs($chief)->post("/criminal-groups/{$group->ulid}/members", ['person' => $p->ulid, 'membership_status' => 'suspected', 'role_in_group' => 'armourer']);

    $add($otherLga)->assertSessionHasErrors(['person' => 'Choose a person you can view.']);
    $add($classified)->assertSessionHasErrors(['person' => 'Choose a person you can view.']);
    $add($visible)->assertSessionHasNoErrors();
    $add($visible)->assertSessionHasErrors(['person' => 'This person is already a member.']);

    PersonFixtures::system();
    expect(CriminalGroupMember::query()->count())->toBe(1)
        // Adding a member does not make them a POI.
        ->and(PersonOfInterest::query()->count())->toBe(0);
});

test('members whose person the viewer cannot see are not shown or counted, and cannot be changed', function () {
    $world = JurisdictionWorld::build();
    $group = CriminalGroup::factory()->in($world->lgas['A1a'])->create();
    $shown = Person::factory()->in($world->lgas['A1a'])->named('Seen', 'Member')->create();
    $hidden = Person::factory()->in($world->lgas['A1b'])->named('Hidden', 'Member')->create();

    foreach ([$shown, $hidden] as $person) {
        $this->actingAs($world->users['state_director'])->post("/criminal-groups/{$group->ulid}/members", ['person' => $person->ulid, 'membership_status' => 'confirmed'])->assertSessionHasNoErrors();
    }

    PersonFixtures::system();
    $hiddenMembership = CriminalGroupMember::query()->where('person_id', $hidden->id)->sole();
    $this->flushSession();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->get("/criminal-groups/{$group->ulid}?tab=members")->assertInertia(fn (Assert $page) => $page
        ->has('members', 1)
        ->where('members.0.person.name', 'Seen Member')
        ->where('members.0.is_poi', false)
        ->where('group.member_count', 1)
        ->missing('members.1'));

    $this->actingAs($chief)->delete("/criminal-groups/{$group->ulid}/members/{$hiddenMembership->ulid}")->assertNotFound();
    $this->actingAs($chief)->put("/criminal-groups/{$group->ulid}/members/{$hiddenMembership->ulid}", ['membership_status' => 'former'])->assertNotFound();

    // Group histories name members by ULID only.
    PersonFixtures::system();
    expect(Activity::query()->where('event', 'member_added')->pluck('description')->unique()->all())->toBe(['Member added']);
});

test('the person page lists group memberships of visible groups', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $group = CriminalGroup::factory()->in($world->lgas['A1a'])->create(['name' => 'Visible Group']);
    $secret = CriminalGroup::factory()->in($world->lgas['A1a'])->classified(Classification::Secret)->create(['name' => 'Secret Group']);

    foreach ([$group, $secret] as $g) {
        $m = new CriminalGroupMember(['membership_status' => 'confirmed']);
        $m->forceFill(['criminal_group_id' => $g->id, 'person_id' => $person->id])->save();
    }

    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}?tab=group_memberships")->assertInertia(fn (Assert $page) => $page
        ->has('groupMemberships', 1)
        ->where('groupMemberships.0.group.name', 'Visible Group'));
});

test('viewing a group is access-logged', function () {
    $world = JurisdictionWorld::build();
    $group = CriminalGroup::factory()->in($world->lgas['A1a'])->create();

    $this->actingAs($world->users['lga_chief'])->get("/criminal-groups/{$group->ulid}")->assertOk();

    PersonFixtures::system();
    expect(RecordAccessLog::query()->where('subject_type', CriminalGroup::class)->where('subject_id', $group->id)->sole()->context)->toBe('tab:overview');
});

test('deleting a group needs State Director or above and a reason', function () {
    $world = JurisdictionWorld::build();
    $group = CriminalGroup::factory()->in($world->lgas['A1a'])->create();

    $this->actingAs($world->users['deputy_state_director'])->delete("/criminal-groups/{$group->ulid}", ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['lga_chief'])->delete("/criminal-groups/{$group->ulid}", ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete("/criminal-groups/{$group->ulid}", [])->assertSessionHasErrors('reason');
    $this->actingAs($world->users['state_director'])->delete("/criminal-groups/{$group->ulid}", ['reason' => 'Merged into another record'])->assertRedirect('/criminal-groups');

    PersonFixtures::system();
    expect(CriminalGroup::query()->count())->toBe(0)
        ->and(CriminalGroup::withTrashed()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'criminal_group_deleted')->sole()->properties['reason'])->toBe('Merged into another record');
});

test('dashboards show POI and group figures through the scopes', function () {
    $world = JurisdictionWorld::build();
    $poi = fn (string $lga, RiskLevel $risk, array $extra = []) => PersonOfInterest::factory()->forPerson(Person::factory()->in($world->lgas[$lga])->create())->create(['risk_level' => $risk, ...$extra]);
    $poi('A1a', RiskLevel::High);
    $poi('A1a', RiskLevel::Critical, ['review_due_at' => now()->subDay()->toDateString()]);
    $poi('A1a', RiskLevel::Low, ['status' => 'cleared']);
    $poi('A1b', RiskLevel::High);
    $poi('B1', RiskLevel::Critical);
    // A secret person's POI is not counted for the deputy.
    PersonOfInterest::factory()->forPerson(Person::factory()->in($world->lgas['A1b'])->classified(Classification::Secret)->create())->create(['risk_level' => RiskLevel::High]);
    CriminalGroup::factory()->in($world->lgas['A1a'])->create(['type' => CriminalGroupType::Cult]);
    CriminalGroup::factory()->in($world->lgas['A1b'])->create(['type' => CriminalGroupType::Cult, 'status' => CriminalGroupStatus::Dormant]);
    CriminalGroup::factory()->in($world->lgas['B1'])->create(['type' => CriminalGroupType::BanditGroup]);

    $this->actingAs($world->users['lga_chief'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('threats.poisByRisk', fn ($rows) => collect($rows)->pluck('total', 'value')->all() === ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0])
        ->where('threats.overdueReviews', 1));

    $this->flushSession();
    $this->actingAs($world->users['deputy_state_director'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('threats.poisByLga', fn ($rows) => collect($rows)->sum('total') === 3)
        ->where('threats.overdueReviews', 1)
        ->where('threats.activeGroups', 1));

    $this->flushSession();
    $this->actingAs($world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('threats.poisByState', fn ($rows) => collect($rows)->sum('total') === 5)
        ->where('threats.groupsByType', fn ($rows) => collect($rows)->pluck('total', 'value')->all() === ['cult' => 1, 'bandit_group' => 1]));
});
