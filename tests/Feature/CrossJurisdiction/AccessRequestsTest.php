<?php

use App\Actions\AccessRequests\DecideAccessRequest;
use App\Actions\AccessRequests\ManageDelegations;
use App\Actions\AccessRequests\RequestAccess;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UserJurisdiction;
use App\Enums\AccessRequestStatus;
use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Notifications\AccessRequestDecided;
use App\Notifications\AccessRequestEscalated;
use App\Notifications\AccessRequestExpired;
use App\Notifications\AccessRequestSubmitted;
use App\Notifications\DelegationChanged;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Notification::fake();
    $this->world = JurisdictionWorld::build();
    $this->person = CrossFixtures::person($this->world->lgas['B1']);
    $this->owner = CrossFixtures::directorOfB1($this->world);
    $this->deputy = CrossFixtures::deputyOfB1($this->world);
    $this->chief = $this->world->users['lga_chief'];
});

/*
|--------------------------------------------------------------------------
| Delegation
|--------------------------------------------------------------------------
*/

test('only a State Director delegates, and only to a deputy of their own state', function () {
    CrossFixtures::signIn($this->owner)->post('/approval-delegations', ['delegate' => $this->deputy->ulid, 'starts_at' => now()->toDateTimeString()])
        ->assertRedirect('/access-requests?tab=delegations');

    Notification::assertSentTo($this->deputy, DelegationChanged::class);

    $otherDeputy = $this->world->users['deputy_state_director'];
    CrossFixtures::signIn($this->owner)->post('/approval-delegations', ['delegate' => $otherDeputy->ulid, 'starts_at' => now()->toDateTimeString()])
        ->assertSessionHasErrors('delegate');

    $freeDeputy = CrossFixtures::deputyOfB1($this->world);
    CrossFixtures::signIn($this->deputy)->post('/approval-delegations', ['delegate' => $freeDeputy->ulid, 'starts_at' => now()->toDateTimeString()])
        ->assertForbidden();

    foreach (['director_general', 'lga_chief', 'super_admin'] as $who) {
        CrossFixtures::signIn($this->world->users[$who])->post('/approval-delegations', ['delegate' => $freeDeputy->ulid, 'starts_at' => now()->toDateTimeString()])
            ->assertForbidden();
    }

    CrossFixtures::system();
    expect(ApprovalDelegation::query()->count())->toBe(1);
});

test('a deputy holds at most one open delegation', function () {
    app(ManageDelegations::class)->create($this->owner, $this->deputy, now()->toImmutable(), null, null);

    CrossFixtures::signIn($this->owner)->post('/approval-delegations', ['delegate' => $this->deputy->ulid, 'starts_at' => now()->addDay()->toDateTimeString()])
        ->assertSessionHasErrors(['delegate' => 'This deputy already holds a delegation. Revoke it first.']);
});

test('a deputy approves only while a delegation is active', function () {
    $request = CrossFixtures::request($this->chief);
    expect($this->deputy->can('decide', $request))->toBeFalse();

    // Starts tomorrow: not yet.
    $delegation = app(ManageDelegations::class)->create($this->owner, $this->deputy, now()->addDay()->toImmutable(), now()->addDays(3)->toImmutable(), null);
    expect($this->deputy->fresh()->can('decide', $request))->toBeFalse();

    $this->travel(2)->days();
    expect($this->deputy->fresh()->can('decide', $request))->toBeTrue();

    // Ended.
    $this->travel(2)->days();
    expect($this->deputy->fresh()->can('decide', $request))->toBeFalse();

    // Revoked.
    CrossFixtures::system();
    $again = app(ManageDelegations::class)->create($this->owner, $this->deputy, now()->toImmutable(), null, null);
    expect($this->deputy->fresh()->can('decide', $request))->toBeTrue();
    CrossFixtures::signIn($this->owner)->post("/approval-delegations/{$again->ulid}/revoke")->assertRedirect();
    expect($this->deputy->fresh()->can('decide', $request))->toBeFalse()
        ->and($delegation->exists)->toBeTrue();
});

test('a delegation ends when either party is deactivated or reposted', function (string $who, string $how) {
    $delegation = app(ManageDelegations::class)->create($this->owner, $this->deputy, now()->toImmutable(), null, null);
    $user = $who === 'director' ? $this->owner : $this->deputy;

    CrossFixtures::system();
    if ($how === 'deactivated') {
        app(DeactivateUser::class)($user, 'Retired from service', $this->world->users['super_admin']);
    } else {
        app(UpdateUserJurisdiction::class)($user, new UserJurisdiction($user->getRole() ?? Role::StateDirector, $this->world->states['A2']), $this->world->users['super_admin']);
    }

    CrossFixtures::system();
    $delegation->refresh();
    expect($delegation->revoked_at)->not->toBeNull()
        ->and($delegation->end_reason)->not->toBeNull()
        ->and($delegation->isActive())->toBeFalse();
})->with(['director', 'deputy'])->with(['deactivated', 'reposted']);

/*
|--------------------------------------------------------------------------
| Requests
|--------------------------------------------------------------------------
*/

test('a request is created only from a valid hit token', function () {
    CrossFixtures::signIn($this->chief)->post('/access-requests', ['token' => 'nonsense', ...CrossFixtures::requestData()])
        ->assertSessionHasErrors('token');

    $token = CrossFixtures::byNin($this->chief)->hits[0]->token;

    // Another user cannot use it.
    CrossFixtures::signIn($this->world->users['state_director'])->post('/access-requests', ['token' => $token, ...CrossFixtures::requestData()])
        ->assertSessionHasErrors('token');

    // A short reason is refused.
    CrossFixtures::signIn($this->chief)->post('/access-requests', ['token' => $token, ...CrossFixtures::requestData(), 'reason' => 'Too short'])
        ->assertSessionHasErrors('reason');

    $response = CrossFixtures::signIn($this->chief)->post('/access-requests', ['token' => $token, ...CrossFixtures::requestData()]);

    CrossFixtures::system();
    $request = RecordAccessRequest::query()->sole();
    $response->assertRedirect("/access-requests/{$request->ulid}");

    expect($request->status)->toBe(AccessRequestStatus::Pending)
        ->and($request->subject_id)->toBe($this->person->id)
        ->and($request->owning_state_id)->toBe($this->person->state_id);

    Notification::assertSentTo($this->owner, AccessRequestSubmitted::class);
    Notification::assertNotSentTo($this->deputy, AccessRequestSubmitted::class);

    // National users cannot request (they never get hits anyway).
    CrossFixtures::signIn($this->world->users['director_general'])->post('/access-requests', ['token' => $token, ...CrossFixtures::requestData()])
        ->assertForbidden();
});

test('one open request per subject, and none while a grant is active', function () {
    CrossFixtures::request($this->chief);

    expect(fn () => CrossFixtures::request($this->chief))->toThrow(ValidationException::class, 'You already have an open request for this record.');

    CrossFixtures::system();
    app(DecideAccessRequest::class)->approve(RecordAccessRequest::query()->sole(), $this->owner, 7, GrantScope::Full, null);

    // With a grant the person is visible, so there is no hit to request from.
    expect(CrossFixtures::byNin($this->chief)->hits)->toBe([]);
});

test('urgent requests notify as critical', function () {
    CrossFixtures::request($this->chief, ['urgency' => 'urgent']);

    Notification::assertSentTo($this->owner, AccessRequestSubmitted::class, fn (AccessRequestSubmitted $n) => $n->urgent === true);
});

test('who may decide: the owning State Director or a delegated deputy; national only when escalated', function () {
    $request = CrossFixtures::request($this->chief);

    expect($this->owner->can('decide', $request))->toBeTrue()
        ->and($this->deputy->can('decide', $request))->toBeFalse()
        ->and($this->world->users['director_general']->can('decide', $request))->toBeFalse()
        ->and($this->world->users['state_director']->can('decide', $request))->toBeFalse()
        ->and($this->world->users['super_admin']->can('decide', $request))->toBeFalse();

    $request->forceFill(['status' => AccessRequestStatus::Escalated, 'escalated_at' => now()])->save();

    expect($this->world->users['director_general']->can('decide', $request))->toBeTrue()
        ->and($this->world->users['ddg_zone_b']->can('decide', $request))->toBeTrue()
        ->and($this->world->users['ddg_zone_a']->can('decide', $request))->toBeFalse()
        ->and($this->owner->can('decide', $request))->toBeTrue();
});

test('nobody decides their own request', function () {
    $otherState = CrossFixtures::person($this->world->lgas['A1a'], ['nin' => '52345678999']);
    $request = app(RequestAccess::class)(
        $this->owner,
        CrossFixtures::byNin($this->owner, '52345678999')->hits[0]->token,
        CrossFixtures::requestData(),
    );

    expect($otherState->exists)->toBeTrue()
        ->and($this->owner->can('decide', $request))->toBeFalse()
        ->and($this->world->users['state_director']->can('decide', $request))->toBeTrue();
});

test('a decider must be cleared for the record', function () {
    CrossFixtures::system();
    $this->person->forceFill(['classification' => Classification::Confidential])->save();
    $request = CrossFixtures::request($this->world->users['deputy_state_director']);

    app(ManageDelegations::class)->create($this->owner, $this->deputy, now()->toImmutable(), null, null);

    // A deputy is cleared for confidential; lower the bar to check the reverse.
    CrossFixtures::system();
    $this->person->forceFill(['classification' => Classification::Secret])->save();

    expect($this->deputy->fresh()->can('decide', $request))->toBeFalse()
        ->and($this->owner->can('decide', $request))->toBeTrue();
});

test('approval creates a grant; duration may be shortened and scope narrowed, never widened', function () {
    $request = CrossFixtures::request($this->chief, ['scope' => 'profile', 'requested_days' => 14]);

    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/approve", ['granted_days' => 30, 'granted_scope' => 'profile'])
        ->assertSessionHasErrors('granted_days');
    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/approve", ['granted_days' => 7, 'granted_scope' => 'full'])
        ->assertSessionHasErrors('granted_scope');
    CrossFixtures::signIn($this->deputy)->post("/access-requests/{$request->ulid}/approve", ['granted_days' => 7, 'granted_scope' => 'profile'])
        ->assertForbidden();

    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/approve", ['granted_days' => 7, 'granted_scope' => 'profile', 'note' => 'Profile only.'])
        ->assertRedirect("/access-requests/{$request->ulid}");

    CrossFixtures::system();
    $grant = RecordAccessGrant::query()->sole();
    expect($grant->scope)->toBe(GrantScope::Profile)
        ->and($grant->user_id)->toBe($this->chief->id)
        ->and($grant->granted_by)->toBe($this->owner->id)
        ->and((int) round($grant->starts_at->diffInDays($grant->expires_at)))->toBe(7)
        ->and($request->fresh()->status)->toBe(AccessRequestStatus::Approved);

    Notification::assertSentTo($this->chief, AccessRequestDecided::class);

    // Decided: no second decision.
    expect($this->owner->can('decide', $request->fresh()))->toBeFalse();
});

test('a full request may be narrowed to profile', function () {
    $request = CrossFixtures::request($this->chief);

    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/approve", ['granted_days' => 14, 'granted_scope' => 'profile'])
        ->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect(RecordAccessGrant::query()->sole()->scope)->toBe(GrantScope::Profile);
});

test('rejection needs a note and tells the requester', function () {
    $request = CrossFixtures::request($this->chief);

    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/reject", ['note' => ''])->assertSessionHasErrors('note');
    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/reject", ['note' => 'Not justified.'])->assertRedirect();

    expect($request->fresh()->status)->toBe(AccessRequestStatus::Rejected);
    Notification::assertSentTo($this->chief, AccessRequestDecided::class);
    CrossFixtures::system();
    expect(RecordAccessGrant::query()->count())->toBe(0);
});

test('the requester may cancel while open; others may not', function () {
    $request = CrossFixtures::request($this->chief);

    CrossFixtures::signIn($this->owner)->post("/access-requests/{$request->ulid}/cancel")->assertForbidden();
    CrossFixtures::signIn($this->chief)->post("/access-requests/{$request->ulid}/cancel")->assertRedirect();

    expect($request->fresh()->status)->toBe(AccessRequestStatus::Cancelled);
});

test('who may view a request', function () {
    $request = CrossFixtures::request($this->chief);

    foreach ([$this->chief, $this->owner, $this->world->users['director_general'], $this->world->users['ddg_zone_b']] as $user) {
        expect($user->can('view', $request))->toBeTrue();
    }

    foreach ([$this->deputy, $this->world->users['state_director'], $this->world->users['ddg_zone_a'], $this->world->users['super_admin']] as $user) {
        expect($user->can('view', $request))->toBeFalse();
    }

    CrossFixtures::signIn($this->world->users['super_admin'])->get("/access-requests/{$request->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->owner)->get("/access-requests/{$request->ulid}")->assertOk();
});

test('escalation timings for both urgencies, then expiry', function (string $urgency, int $minutes) {
    $request = CrossFixtures::request($this->chief, ['urgency' => $urgency]);

    $this->travel($minutes - 1)->minutes();
    $this->artisan('buffalo:escalate-access-requests')->assertSuccessful();
    expect($request->fresh()->status)->toBe(AccessRequestStatus::Pending);

    $this->travel(2)->minutes();
    $this->artisan('buffalo:escalate-access-requests')->assertSuccessful();
    expect($request->fresh()->status)->toBe(AccessRequestStatus::Escalated);

    Notification::assertSentTo($this->world->users['director_general'], AccessRequestEscalated::class);
    Notification::assertSentTo($this->world->users['ddg_zone_b'], AccessRequestEscalated::class);
    Notification::assertNotSentTo($this->world->users['ddg_zone_a'], AccessRequestEscalated::class);

    $this->travel(7)->days();
    $this->artisan('buffalo:escalate-access-requests')->assertSuccessful();
    expect($request->fresh()->status)->toBe(AccessRequestStatus::Expired);
    Notification::assertSentTo($this->chief, AccessRequestExpired::class);
})->with([
    'urgent after 2 hours' => ['urgent', 120],
    'normal after 48 hours' => ['normal', 2880],
]);

test('the index shows each role its own tabs', function () {
    CrossFixtures::signIn($this->chief)->get('/access-requests')
        ->assertInertia(fn ($page) => $page->component('access-requests/index')->where('tabs', ['mine', 'grants']));
    CrossFixtures::signIn($this->owner)->get('/access-requests')
        ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => collect($tabs)->contains('decide') && collect($tabs)->contains('delegations')));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/access-requests')
        ->assertInertia(fn ($page) => $page->where('tabs', fn ($tabs) => collect($tabs)->contains('oversight')));
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/access-requests')->assertForbidden();
});
