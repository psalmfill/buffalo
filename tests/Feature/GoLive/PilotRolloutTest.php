<?php

use App\Actions\Auth\IssueAccountSetupLink;
use App\Enums\LoginEvent;
use App\Models\Lga;
use App\Models\LoginHistory;
use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Auth\StepUp;
use App\Support\Onboarding\OnboardingStats;
use App\Support\Rollout\Rollout;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

/*
| Pilot rollout (Phase 9B.3): officers of a state that is not live cannot
| sign in; national users and super admins are unaffected; the switch needs
| step-up and a reason.
*/

beforeEach(function () {
    config(['buffalo.rollout.enforce' => true]);
    $this->seed(ZonesAndStatesSeeder::class);
    $this->lagos = State::query()->where('code', 'LA')->firstOrFail();
});

/** Password, then the current TOTP code: a complete sign-in. */
function signInFully(object $test, User $user): TestResponse
{
    $test->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));

    return $test->post(route('two-factor.login.store'), ['code' => $code]);
}

function rolloutEvents(User $user): array
{
    return LoginHistory::query()->where('user_id', $user->id)->orderBy('id')->pluck('event')
        ->map(fn ($event) => $event instanceof LoginEvent ? $event->value : $event)->all();
}

test('a state officer of a state that is not live is refused after password and 2FA', function (string $factory) {
    $user = User::factory()->{$factory}($factory === 'lgaChief' ? null : $this->lagos)->create();
    $state = State::query()->findOrFail($user->state_id);
    expect($state->is_live)->toBeFalse();

    signInFully($this, $user);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', Rollout::NOT_LIVE_MESSAGE);

    $this->assertGuest();
    expect(rolloutEvents($user))->toContain('state_not_live')
        ->not->toContain('login_success');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('status', Rollout::NOT_LIVE_MESSAGE));
})->with(['stateDirector', 'deputyStateDirector', 'lgaChief']);

test('once the state is live the same officer signs in normally', function () {
    $this->lagos->forceFill(['is_live' => true, 'went_live_at' => now()])->save();
    $user = User::factory()->stateDirector($this->lagos)->create();

    signInFully($this, $user);

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(rolloutEvents($user))->toContain('login_success')->not->toContain('state_not_live');
});

test('national users and super admins are never affected', function (string $factory) {
    expect(State::query()->where('is_live', true)->count())->toBe(0);
    $user = User::factory()->{$factory}()->create();

    signInFully($this, $user);

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
})->with(['directorGeneral', 'ddg', 'superAdmin']);

test('switching a state off ends its officers\' sessions on their next request', function () {
    $this->lagos->forceFill(['is_live' => true])->save();
    $user = User::factory()->stateDirector($this->lagos)->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->lagos->forceFill(['is_live' => false])->save();

    $this->actingAs($user)->getJson(route('dashboard'))
        ->assertForbidden()
        ->assertJson(['message' => Rollout::NOT_LIVE_MESSAGE]);
    expect(rolloutEvents($user))->toContain('state_not_live');
});

test('officers of a state that is not live can still enrol two-factor authentication', function () {
    $user = User::factory()->stateDirector($this->lagos)->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.setup'))
        ->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('the gate is off when rollout enforcement is switched off (tests and development only)', function () {
    config(['buffalo.rollout.enforce' => false]);
    $user = User::factory()->stateDirector($this->lagos)->create();

    expect(Rollout::blocks($user))->toBeFalse();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('a super admin sees the rollout page with every state', function () {
    $this->actingAs(User::factory()->superAdmin()->create())->get(route('system.rollout'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('system/rollout')->has('states', 38));
});

test('other roles may not see the rollout page', function (string $factory) {
    $this->actingAs(User::factory()->{$factory}()->create())->get(route('system.rollout'))->assertForbidden();
})->with(['directorGeneral', 'ddg']);

test('making a state live needs step-up and a reason, and is audited', function () {
    $admin = User::factory()->superAdmin()->create();
    $url = route('system.rollout.update', $this->lagos);

    // No recent TOTP code: step-up required, nothing changes.
    $this->actingAs($admin)->putJson($url, ['live' => true, 'reason' => 'Pilot start'])->assertStatus(428);
    expect($this->lagos->fresh()->is_live)->toBeFalse();

    $this->withSession([StepUp::SESSION_KEY => now()->timestamp]);

    $this->actingAs($admin)->put($url, ['live' => true, 'reason' => ''])->assertSessionHasErrors('reason');

    $this->actingAs($admin)->put($url, ['live' => true, 'reason' => 'Pilot start approved by the DG'])->assertRedirect();

    $state = $this->lagos->fresh();
    expect($state->is_live)->toBeTrue()
        ->and($state->went_live_at)->not->toBeNull();

    $event = Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('event', 'state_went_live')->latest('id')->firstOrFail();
    expect($event->properties['reason'])->toBe('Pilot start approved by the DG')
        ->and($event->causer_id)->toBe($admin->id)
        ->and(Activity::query()->where('subject_type', $state->getMorphClass())->where('subject_id', $state->id)->where('log_name', 'model')->exists())->toBeTrue();

    // Already live: refused.
    $this->actingAs($admin)->put($url, ['live' => true, 'reason' => 'again'])->assertSessionHasErrors('state');

    // And back.
    $this->actingAs($admin)->put($url, ['live' => false, 'reason' => 'Pilot paused'])->assertRedirect();
    expect($state->fresh()->is_live)->toBeFalse()->and($state->fresh()->went_live_at)->toBeNull();
});

test('other roles cannot switch a state', function () {
    $this->withSession([StepUp::SESSION_KEY => now()->timestamp]);

    $this->actingAs(User::factory()->directorGeneral()->create())
        ->put(route('system.rollout.update', $this->lagos), ['live' => true, 'reason' => 'x'])
        ->assertForbidden();
    expect($this->lagos->fresh()->is_live)->toBeFalse();
});

test('onboarding counts follow each account\'s stage', function () {
    User::factory()->stateDirector($this->lagos)->create(['password_changed_at' => null, 'two_factor_confirmed_at' => null]);
    $linked = User::factory()->deputyStateDirector($this->lagos)->create(['password_changed_at' => null, 'two_factor_confirmed_at' => null]);
    app(IssueAccountSetupLink::class)($linked);
    User::factory()->lgaChief(Lga::factory()->create(['state_id' => $this->lagos->id]))->withoutTwoFactor()->create(['password_changed_at' => now()]);
    User::factory()->stateDirector($this->lagos)->create(['password_changed_at' => now()]);
    User::factory()->stateDirector($this->lagos)->inactive()->create();
    User::factory()->directorGeneral()->create(['password_changed_at' => now()]);

    $rows = collect(app(OnboardingStats::class)->perState());
    $lagos = $rows->firstWhere('state.code', 'LA');
    $national = $rows->firstWhere('state', null);

    expect($lagos)->toMatchArray(['total' => 4, 'no_link' => 1, 'link_issued' => 1, 'no_2fa' => 1, 'ready' => 1])
        ->and($national['total'])->toBe(1)
        ->and($national['ready'])->toBe(1);
});
