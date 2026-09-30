<?php

use App\Actions\AccessRequests\DecideAccessRequest;
use App\Actions\AccessRequests\RequestAccess;
use App\Actions\AccessRequests\RevokeGrant;
use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Events\AccessGrantEnded;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\PoliticalOffice;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessLog;
use App\Notifications\AccessGrantChanged;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/** Refused: forbidden, not found, or rejected by validation. Never a success. */
function assertRefused(TestResponse $response): void
{
    $refused = in_array($response->status(), [403, 404], true)
        || ($response->status() === 302 && session()->has('errors'));

    expect($refused)->toBeTrue("Expected the write to be refused, got {$response->status()}.");
}

beforeEach(function () {
    Notification::fake();
    $this->world = JurisdictionWorld::build();
    $this->person = CrossFixtures::person($this->world->lgas['B1'], ['phone' => '+2348035550100', 'address' => '12 Market Road']);
    $this->owner = CrossFixtures::directorOfB1($this->world);
    $this->chief = $this->world->users['lga_chief'];
});

test('a grant makes the person visible to the holder only', function () {
    $index = fn ($user) => CrossFixtures::signIn($user)->get('/persons')->inertiaProps('persons.data');

    expect(collect($index($this->chief))->pluck('ulid'))->not->toContain($this->person->ulid);
    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertNotFound();

    CrossFixtures::grant($this->chief, $this->owner);

    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/persons/{$this->person->ulid}")->assertNotFound();
});

test('a grant never overrides classification', function () {
    CrossFixtures::grant($this->chief, $this->owner);

    CrossFixtures::system();
    $this->person->forceFill(['classification' => Classification::Confidential])->save();

    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertNotFound();
});

test('a profile grant shows the profile only; no NIN, phone, address, place or links', function () {
    CrossFixtures::grant($this->chief, $this->owner, GrantScope::Profile);
    PersonOfInterest::factory()->forPerson($this->person)->create();

    $response = CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertOk();
    $person = $response->inertiaProps('person');

    expect($response->inertiaProps('tabs'))->toBe(['profile'])
        ->and($person)->not->toHaveKeys(['nin', 'phone', 'address'])
        ->and($person['state'])->toBeNull()
        ->and($person['lga'])->toBeNull()
        ->and($response->inertiaProps('grant.scope.value'))->toBe('profile')
        ->and($response->inertiaProps('can'))->each->toBeFalse();

    // Other tabs cannot be forced.
    $forced = CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}?tab=poi");
    expect($forced->inertiaProps('tab'))->toBe('profile')->and($forced->inertiaProps('poi'))->toBeNull();

    $strings = implode("\n", CrossFixtures::strings($response->inertiaProps()));
    expect($strings)->not->toContain(CrossFixtures::NIN)
        ->not->toContain('12 Market Road')
        ->not->toContain($this->world->lgas['B1']->name);
});

test('a full grant adds NIN, phone, address and the linked tabs, filtered by clearance', function () {
    CrossFixtures::grant($this->chief, $this->owner, GrantScope::Full);
    CrossFixtures::system();
    PersonOfInterest::factory()->forPerson($this->person)->create(['known_offences' => 'Armed robbery']);
    PoliticalOffice::factory()->forPerson($this->person)->create();
    $open = CriminalGroup::factory()->in($this->world->lgas['B1'])->create(['name' => 'Open Ring']);
    $secret = CriminalGroup::factory()->in($this->world->lgas['B1'])->classified(Classification::Secret)->create(['name' => 'Hidden Ring']);
    foreach ([$open, $secret] as $group) {
        (new CriminalGroupMember(['membership_status' => 'confirmed']))->forceFill(['criminal_group_id' => $group->id, 'person_id' => $this->person->id])->save();
    }

    $response = CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertOk();

    expect($response->inertiaProps('person.nin'))->toBe(CrossFixtures::NIN)
        ->and($response->inertiaProps('person.address'))->toBe('12 Market Road')
        ->and($response->inertiaProps('tabs'))->toBe(['profile', 'associations', 'political_offices', 'poi', 'group_memberships']);

    $poi = CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}?tab=poi")->inertiaProps('poi');
    expect($poi['known_offences'])->toBe('Armed robbery')->and($poi)->not->toHaveKey('ulid');

    $groups = CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}?tab=group_memberships")->inertiaProps('grantMemberships');
    expect(collect($groups)->pluck('group.name')->all())->toBe(['Open Ring']);

    expect(CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}?tab=political_offices")->inertiaProps('politicalOffices.current'))->toHaveCount(1);
});

test('grant views are access-logged with context grant', function () {
    CrossFixtures::grant($this->chief, $this->owner);

    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertOk();

    CrossFixtures::system();
    expect(RecordAccessLog::query()->where('user_id', $this->chief->id)->where('subject_id', $this->person->id)->value('context'))->toBe('grant');
});

test('grant holders can never write', function () {
    CrossFixtures::grant($this->chief, $this->owner);
    CrossFixtures::system();
    $own = Person::factory()->in($this->world->lgas['A1a'])->create();
    $group = CriminalGroup::factory()->in($this->world->lgas['A1a'])->create();
    $before = [Person::query()->count(), $this->person->aliases()->count(), CriminalGroupMember::query()->count(), PersonOfInterest::query()->count(), PoliticalOffice::query()->count()];
    $url = "/persons/{$this->person->ulid}";

    assertRefused(CrossFixtures::signIn($this->chief)->put($url, ['first_name' => 'Changed', 'last_name' => 'Name', 'gender' => 'male', 'classification' => 'restricted', 'lga' => $this->world->lgas['B1']->ulid]));
    assertRefused(CrossFixtures::signIn($this->chief)->delete($url, ['reason' => 'Duplicate record']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("{$url}/photos", ['photo' => UploadedFile::fake()->image('p.jpg')]));
    assertRefused(CrossFixtures::signIn($this->chief)->post("{$url}/aliases", ['alias' => 'New Alias']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("{$url}/associations", ['person' => $own->ulid, 'relationship' => 'associate']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("/persons/{$own->ulid}/associations", ['person' => $this->person->ulid, 'relationship' => 'associate']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("{$url}/person-of-interest", ['threat_level' => 'high', 'known_offences' => 'x']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("{$url}/political-offices", ['office' => 'chairman']));
    assertRefused(CrossFixtures::signIn($this->chief)->post("/criminal-groups/{$group->ulid}/members", ['person' => $this->person->ulid, 'membership_status' => 'active']));

    CrossFixtures::system();
    expect([Person::query()->count(), $this->person->aliases()->count(), CriminalGroupMember::query()->count(), PersonOfInterest::query()->count(), PoliticalOffice::query()->count()])->toBe($before)
        ->and($this->person->fresh()->first_name)->toBe('Garba')
        ->and($this->chief->can('update', $this->person))->toBeFalse()
        ->and($this->chief->can('delete', $this->person))->toBeFalse()
        ->and($this->chief->can('link', $this->person))->toBeFalse();
});

test('the expiry job reminds a day before and ends access at expiry', function () {
    $grant = CrossFixtures::grant($this->chief, $this->owner, GrantScope::Full, 7);

    $this->travel(5)->days();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();
    Notification::assertNotSentTo($this->chief, AccessGrantChanged::class);

    $this->travel(25)->hours();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();
    Notification::assertSentToTimes($this->chief, AccessGrantChanged::class, 1);
    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertOk();

    $this->travel(1)->days();
    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertNotFound();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();
    Notification::assertSentToTimes($this->chief, AccessGrantChanged::class, 2);

    CrossFixtures::system();
    expect($grant->fresh()->expiry_notified_at)->not->toBeNull();
});

test('revocation by the granter, owning State Director or national oversight; nobody else', function () {
    $grant = CrossFixtures::grant($this->chief, $this->owner);
    $deputy = CrossFixtures::deputyOfB1($this->world);

    foreach ([$this->chief, $deputy, $this->world->users['state_director'], $this->world->users['ddg_zone_a'], $this->world->users['super_admin']] as $user) {
        expect($user->can('revoke', $grant))->toBeFalse();
    }

    foreach ([$this->owner, $this->world->users['director_general'], $this->world->users['ddg_zone_b']] as $user) {
        expect($user->can('revoke', $grant))->toBeTrue();
    }

    CrossFixtures::signIn($this->world->users['state_director'])->post("/access-grants/{$grant->ulid}/revoke", ['reason' => 'No longer needed'])->assertForbidden();
    CrossFixtures::signIn($this->owner)->post("/access-grants/{$grant->ulid}/revoke", ['reason' => 'No longer needed'])->assertRedirect();

    CrossFixtures::system();
    expect($grant->fresh()->revoked_at)->not->toBeNull();
    Notification::assertSentTo($this->chief, AccessGrantChanged::class);
    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")->assertNotFound();
});

test('within a state: an LGA chief gets a hit for another LGA and the State Director decides', function () {
    CrossFixtures::system();
    $neighbour = Person::factory()->in($this->world->lgas['A1b'])->named('Local', 'Suspect')->create(['nin' => '52345678777']);

    $hits = CrossFixtures::byNin($this->chief, '52345678777')->hits;
    expect($hits)->toHaveCount(1)->and($hits[0]->state)->toBe($this->world->states['A1']->name);

    $request = app(RequestAccess::class)($this->chief, $hits[0]->token, CrossFixtures::requestData());
    expect($this->world->users['state_director']->can('decide', $request))->toBeTrue()
        ->and($this->owner->can('decide', $request))->toBeFalse();

    app(DecideAccessRequest::class)->approve($request, $this->world->users['state_director'], 7, GrantScope::Profile, null);
    CrossFixtures::signIn($this->chief)->get("/persons/{$neighbour->ulid}")->assertOk();
});

test('super admin has no access to requests, grants or persons', function () {
    $grant = CrossFixtures::grant($this->chief, $this->owner);
    $admin = $this->world->users['super_admin'];

    CrossFixtures::signIn($admin)->get('/access-requests')->assertForbidden();
    CrossFixtures::signIn($admin)->get("/access-requests/{$grant->request->ulid}")->assertForbidden();
    CrossFixtures::signIn($admin)->post("/access-grants/{$grant->ulid}/revoke", ['reason' => 'Admin cleanup'])->assertForbidden();
    expect(CrossFixtures::signIn($admin)->get("/persons/{$this->person->ulid}")->status())->toBeIn([403, 404]);

    CrossFixtures::system();
    expect(RecordAccessGrant::query()->active()->count())->toBe(1);
});

test('a grant shows linked records only on the person page, never through their own scopes', function () {
    CrossFixtures::system();
    $poi = PersonOfInterest::factory()->forPerson($this->person)->create();
    $office = PoliticalOffice::factory()->forPerson($this->person)->create();
    CrossFixtures::grant($this->chief, $this->owner, GrantScope::Full);

    $visible = app(JurisdictionContext::class)->actingAs($this->chief, fn () => [
        PersonOfInterest::query()->count(),
        PoliticalOffice::query()->count(),
    ]);

    expect($visible)->toBe([0, 0])
        ->and($this->chief->can('update', $poi))->toBeFalse()
        ->and($this->chief->can('update', $office))->toBeFalse();
});

test('revoking a grant broadcasts AccessGrantEnded to the holder with the grant ULID only', function () {
    Event::fake([AccessGrantEnded::class]);
    $grant = CrossFixtures::grant($this->chief, $this->owner);

    app(RevokeGrant::class)($grant, $this->owner, 'Case closed');

    Event::assertDispatched(AccessGrantEnded::class, function (AccessGrantEnded $event) use ($grant) {
        return $event->broadcastOn()->name === 'private-users.'.$this->chief->ulid
            && $event->broadcastWith() === ['grant' => $grant->ulid]
            && $event->broadcastAs() === 'access-grant.ended';
    });
});

test('an expired grant broadcasts AccessGrantEnded once', function () {
    Event::fake([AccessGrantEnded::class]);
    $grant = CrossFixtures::grant($this->chief, $this->owner, GrantScope::Profile, 7);

    $this->travel(8)->days();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();
    $this->artisan('buffalo:expire-access-grants')->assertSuccessful();

    Event::assertDispatchedTimes(AccessGrantEnded::class, 1);
    Event::assertDispatched(AccessGrantEnded::class, fn (AccessGrantEnded $event) => $event->broadcastWith() === ['grant' => $grant->ulid]);
});

test('the person page under a grant carries the grant ULID for the live end', function () {
    $grant = CrossFixtures::grant($this->chief, $this->owner);

    CrossFixtures::signIn($this->chief)->get("/persons/{$this->person->ulid}")
        ->assertInertia(fn ($page) => $page->where('grant.ulid', $grant->ulid));
});
