<?php

use App\Actions\Users\DeactivateUser;
use App\Enums\LoginEvent;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Auth\SessionKeys;
use App\Support\Auth\SessionTerminator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

const GENERIC_LOGIN_ERROR = 'These credentials do not match our records.';

function attemptLogin(object $test, string $email, string $password = 'wrong-password'): TestResponse
{
    return $test->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

/**
 * Session data as stamped at login for $user, with optional overrides.
 *
 * @return array<string, mixed>
 */
function stampedSession(User $user, array $overrides = []): array
{
    $user = $user->fresh();

    return array_merge([
        SessionKeys::VERSION => $user->session_version,
        SessionKeys::TOKEN => $user->session_token,
        SessionKeys::STARTED_AT => now()->getTimestamp(),
    ], $overrides);
}

function historyEvents(User $user): array
{
    return LoginHistory::query()->where('user_id', $user->id)->orderBy('id')->pluck('event')->map->value->all();
}

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('five consecutive failed passwords lock the account for 15 minutes', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    foreach (range(1, 4) as $i) {
        attemptLogin($this, $user->email);
    }

    expect($user->fresh()->locked_until)->toBeNull()
        ->and($user->fresh()->failed_login_count)->toBe(4);

    attemptLogin($this, $user->email);
    $user->refresh();

    expect($user->locked_until?->diffInMinutes(now(), true))->toBeGreaterThan(14.9)->toBeLessThan(15.1)
        ->and($user->isAccessBlocked())->toBeTrue();

    // Even the right password fails while locked, with the generic message.
    attemptLogin($this, $user->email, 'password')->assertSessionHasErrors(['email' => GENERIC_LOGIN_ERROR]);
    $this->assertGuest();
});

test('the login error is identical for unknown, wrong, locked and inactive accounts', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();
    $locked = User::factory()->directorGeneral()->withoutTwoFactor()->locked()->create();
    $inactive = User::factory()->directorGeneral()->withoutTwoFactor()->inactive()->create();

    // Every case produces exactly the same message.
    foreach ([
        ['nobody@buffalo.test', 'wrong-password'],
        [$user->email, 'wrong-password'],
        [$locked->email, 'password'],
        [$inactive->email, 'password'],
    ] as [$email, $password]) {
        attemptLogin($this, $email, $password)->assertSessionHasErrors(['email' => GENERIC_LOGIN_ERROR]);
    }

    $this->assertGuest();
});

test('each further lock within 24 hours doubles the duration, capped at 24 hours', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();
    $durations = [];

    foreach (range(1, 8) as $round) {
        foreach (range(1, 5) as $i) {
            attemptLogin($this, $user->email);
        }

        $user->refresh();
        $durations[] = (int) round($user->locked_until->diffInMinutes(now(), true));
        $this->travelTo($user->locked_until->addMinute());
    }

    expect($durations)->toBe([15, 30, 60, 120, 240, 480, 960, 1440]);
});

test('a lock more than 24 hours after the previous one starts again at 15 minutes', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();
    $user->forceFill(['lockout_count' => 4, 'last_locked_at' => now()->subHours(25)])->save();

    foreach (range(1, 5) as $i) {
        attemptLogin($this, $user->email);
    }

    expect((int) round($user->fresh()->locked_until->diffInMinutes(now(), true)))->toBe(15);
});

test('a successful login resets the failure counter', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    foreach (range(1, 4) as $i) {
        attemptLogin($this, $user->email);
    }

    attemptLogin($this, $user->email, 'password');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->failed_login_count)->toBe(0);

    Auth::logout();

    foreach (range(1, 4) as $i) {
        attemptLogin($this, $user->email);
    }

    expect($user->fresh()->locked_until)->toBeNull();
});

test('failed two-factor codes count toward the lockout', function () {
    $user = User::factory()->directorGeneral()->create();

    attemptLogin($this, $user->email, 'password')->assertRedirect(route('two-factor.login'));

    foreach (range(1, 5) as $i) {
        $this->withSession(['login.id' => $user->id])->post(route('two-factor.login.store'), ['code' => '000000']);
    }

    expect($user->fresh()->isAccessBlocked())->toBeTrue()
        ->and(historyEvents($user))->toContain('two_factor_failed', 'locked');

    // The right code no longer works while locked.
    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    $this->withSession(['login.id' => $user->id])->post(route('two-factor.login.store'), ['code' => $code]);
    $this->assertGuest();
});

test('a lock notifies every active super admin', function () {
    $admins = User::factory()->superAdmin()->count(2)->create();
    $inactiveAdmin = User::factory()->superAdmin()->inactive()->create();
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    foreach (range(1, 5) as $i) {
        attemptLogin($this, $user->email);
    }

    $notifications = DatabaseNotification::query()->get();

    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('notifiable_id')->sort()->values()->all())->toBe($admins->pluck('id')->sort()->values()->all())
        ->and($notifications->first()->data['type'])->toBe('account_locked')
        ->and($notifications->first()->data['user_ulid'])->toBe($user->ulid)
        ->and($notifications->pluck('notifiable_id'))->not->toContain($inactiveAdmin->id);
});

test('inactive users cannot log in', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->inactive()->create();

    attemptLogin($this, $user->email, 'password')->assertSessionHasErrors(['email' => GENERIC_LOGIN_ERROR]);
    $this->assertGuest();
});

test('deactivation ends the user\'s existing sessions', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = User::factory()->directorGeneral()->create();
    $session = stampedSession($user);

    $this->actingAs($user)->withSession($session)->get('/dashboard')->assertOk();

    app(DeactivateUser::class)($user->fresh(), 'Left the service', $admin);

    $this->actingAs($user->fresh())->withSession($session)->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('signing in elsewhere signs out the earlier session', function () {
    $user = User::factory()->directorGeneral()->create(['session_token' => 'token-of-the-new-login']);

    $this->actingAs($user)
        ->withSession(stampedSession($user, [SessionKeys::TOKEN => 'token-of-the-old-login']))
        ->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You were signed out because your account signed in elsewhere.');

    $this->assertGuest();
    expect(historyEvents($user))->toBe(['signed_out_elsewhere']);
});

test('multiple sessions are allowed when single-session mode is off', function () {
    config(['buffalo.auth.single_session' => false]);
    $user = User::factory()->directorGeneral()->create(['session_token' => 'token-of-the-new-login']);

    $this->actingAs($user)
        ->withSession(stampedSession($user, [SessionKeys::TOKEN => 'token-of-the-old-login']))
        ->get('/dashboard')
        ->assertOk();
});

test('a real second login replaces the session token', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    attemptLogin($this, $user->email, 'password');
    $first = $user->fresh()->session_token;
    Auth::logout();
    attemptLogin($this, $user->email, 'password');

    expect($user->fresh()->session_token)->not->toBeNull()->not->toBe($first);
});

test('sessions older than the absolute maximum age end', function () {
    $user = User::factory()->directorGeneral()->create();

    $this->actingAs($user)
        ->withSession(stampedSession($user, [SessionKeys::STARTED_AT => now()->subHours(11)->getTimestamp()]))
        ->get('/dashboard')
        ->assertOk();

    $this->actingAs($user)
        ->withSession(stampedSession($user, [SessionKeys::STARTED_AT => now()->subHours(12)->subMinute()->getTimestamp()]))
        ->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session has expired. Please sign in again.');

    expect(historyEvents($user))->toBe(['session_expired']);
});

test('session settings: 30 minute idle timeout, http-only lax cookies, secure on https', function () {
    expect(config('session.lifetime'))->toBe(30)
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('buffalo.auth.session_max_hours'))->toBe(12)
        ->and(config('buffalo.auth.single_session'))->toBeTrue();
});

test('remember me is ignored', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $this->assertAuthenticatedAs($user);
    $response->assertCookieMissing(Auth::guard()->getRecallerName());
});

test('a successful login records the time and address', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_login_ip)->toBe('10.1.2.3');
});

test('every login event is recorded in the history', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    attemptLogin($this, 'unknown@buffalo.test');
    attemptLogin($this, $user->email);
    attemptLogin($this, $user->email, 'password');
    $this->post(route('logout'));

    expect(LoginHistory::query()->where('email_attempted', 'unknown@buffalo.test')->value('event'))->toBe(LoginEvent::LoginFailed)
        ->and(historyEvents($user))->toBe(['login_failed', 'login_success', 'logout']);
});

test('the per-IP login rate limit still applies', function () {
    $this->withMiddleware(ThrottleRequests::class);

    foreach (range(1, 20) as $i) {
        $this->post(route('login.store'), ['email' => "user{$i}@buffalo.test", 'password' => 'x']);
    }

    $this->post(route('login.store'), ['email' => 'user21@buffalo.test', 'password' => 'x'])->assertTooManyRequests();
});

test('session termination only ever increases the version, even from a stale model', function () {
    $user = User::factory()->directorGeneral()->create();
    $stale = User::query()->find($user->id);

    app(SessionTerminator::class)->terminateAll($user);
    app(SessionTerminator::class)->terminateAll($user);
    app(SessionTerminator::class)->terminateAll($stale);

    expect($user->fresh()->session_version)->toBe(3);
});
