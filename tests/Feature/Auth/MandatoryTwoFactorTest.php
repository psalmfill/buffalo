<?php

use App\Actions\Auth\IssueAccountSetupLink;
use App\Actions\Auth\ResetTwoFactor;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Auth\SessionKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

function unenrolledUser(): User
{
    return User::factory()->directorGeneral()->withoutTwoFactor()->create();
}

function currentTotp(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
}

test('a user without 2FA is sent to enrolment from every other page', function () {
    $user = unenrolledUser();

    foreach (['/dashboard', '/settings/security', '/settings/appearance', '/horizon'] as $uri) {
        $this->actingAs($user)->get($uri)->assertRedirect(route('two-factor.setup'));
    }
});

test('a user without 2FA gets a 403 on JSON requests', function () {
    $this->actingAs(unenrolledUser())
        ->getJson('/dashboard')
        ->assertForbidden()
        ->assertJson(['message' => 'Two-factor authentication must be set up first.']);
});

test('a user without 2FA can reach the enrolment routes and log out', function () {
    $user = unenrolledUser();
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($session)->get(route('two-factor.setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-setup')->where('twoFactorConfirmed', false));
    $this->actingAs($user)->withSession($session)->post(route('two-factor.enable'))->assertRedirect();
    $this->actingAs($user)->withSession($session)->getJson(route('two-factor.qr-code'))->assertOk();
    $this->actingAs($user)->withSession($session)->getJson(route('two-factor.secret-key'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('the full enrolment flow works with a locally generated QR code', function () {
    Http::preventStrayRequests();
    $user = unenrolledUser();
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($session)->post(route('two-factor.enable'))->assertRedirect();

    $qr = $this->actingAs($user)->withSession($session)->getJson(route('two-factor.qr-code'))->assertOk()->json('svg');
    expect($qr)->toStartWith('<svg');

    $this->actingAs($user)->withSession($session)
        ->post(route('two-factor.confirm'), ['code' => currentTotp($user)])
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SessionKeys::SHOW_RECOVERY_CODES, true);

    $user = $user->fresh();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();

    // Recovery codes are shown once, then the user confirms saving them.
    // (Test requests do not carry the session cookie, so pass it along.)
    $this->actingAs($user)->withSession([...$session, SessionKeys::SHOW_RECOVERY_CODES => true])->get(route('two-factor.setup'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-setup')->where('twoFactorConfirmed', true));

    $this->actingAs($user)->withSession([SessionKeys::SHOW_RECOVERY_CODES => true])
        ->post(route('two-factor.setup.complete'), ['saved' => '1'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing(SessionKeys::SHOW_RECOVERY_CODES);

    $this->actingAs($user)->get(route('two-factor.setup'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('finishing enrolment requires confirming the codes were saved', function () {
    $user = User::factory()->directorGeneral()->create();

    $this->actingAs($user)->post(route('two-factor.setup.complete'))->assertSessionHasErrors('saved');
});

test('two-factor authentication cannot be disabled', function () {
    $user = User::factory()->directorGeneral()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertForbidden();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('confirmed two-factor authentication cannot be re-keyed by the user', function () {
    $user = User::factory()->directorGeneral()->create();
    $secret = $user->two_factor_secret;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'), ['force' => true])
        ->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

test('regenerating recovery codes requires password confirmation', function () {
    $user = User::factory()->directorGeneral()->create();
    $before = $user->recoveryCodes();

    $this->actingAs($user)->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->recoveryCodes())->toBe($before);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect();

    expect($user->fresh()->recoveryCodes())->not->toBe($before)->toHaveCount(8);
});

test('ResetTwoFactor clears 2FA, ends sessions and forces re-enrolment', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();

    // An active session exists, stamped with the current session version.
    $version = $user->fresh()->session_version;
    $this->actingAs($user)->withSession([SessionKeys::VERSION => $version])->get('/dashboard')->assertOk();

    app(ResetTwoFactor::class)($user->fresh());

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();

    // That session ends at its next request.
    expect($user->fresh()->session_version)->toBe($version + 1);

    $this->actingAs($user->fresh())->withSession([SessionKeys::VERSION => $version])
        ->get('/dashboard')
        ->assertRedirect(route('login'));
    $this->assertGuest();

    // The next login leads to enrolment.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->get('/dashboard')->assertRedirect(route('two-factor.setup'));

    Notification::assertSentTo($user, TwoFactorResetNotification::class);
});

test('ResetTwoFactor sends no email when mail is not configured', function () {
    config(['mail.default' => 'log']);
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();

    app(ResetTwoFactor::class)($user);

    Notification::assertNothingSent();
});

test('completing account setup leads straight to two-factor enrolment without a password prompt', function () {
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();
    $token = basename(parse_url(app(IssueAccountSetupLink::class)($user), PHP_URL_PATH));

    $this->post(route('account-setup.store', $token), [
        'password' => 'Setup-Password-2026!',
        'password_confirmation' => 'Setup-Password-2026!',
    ])->assertRedirect(route('two-factor.setup'));

    $this->get(route('two-factor.setup'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-setup'));
});

test('the two-factor provider generates secrets locally', function () {
    Http::preventStrayRequests();

    expect(app(TwoFactorAuthenticationProvider::class)->generateSecretKey())->toHaveLength(16);
});
