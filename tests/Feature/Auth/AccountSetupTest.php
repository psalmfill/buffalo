<?php

use App\Actions\Auth\IssueAccountSetupLink;
use App\Actions\Auth\SendAccountSetupLink;
use App\Actions\Users\CreateUser;
use App\Actions\Users\UserJurisdiction;
use App\Enums\LoginEvent;
use App\Enums\Role;
use App\Models\AccountSetupToken;
use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\AccountSetupNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

const SETUP_PASSWORD = 'Setup-Password-2026!';

function newUserWithLink(): array
{
    $user = app(CreateUser::class)(['name' => 'New Officer', 'email' => 'new.officer@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral));
    $url = app(IssueAccountSetupLink::class)($user);

    return [$user, $url, basename(parse_url($url, PHP_URL_PATH))];
}

function assertGenericInvalidPage($response): void
{
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/account-setup-invalid')
        ->missing('token')
        ->missing('email'));
}

test('a valid link shows the setup form with the password rules', function () {
    [$user, $url] = newUserWithLink();

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/account-setup')
        ->where('email', 'new.officer@buffalo.test')
        ->where('userName', 'New Officer')
        // The shared application name must not be overridden by page props.
        ->where('name', config('app.name'))
        ->has('passwordRequirements', 5));
});

test('setting the password consumes the link, logs the user in and records the event', function () {
    [$user, $url, $token] = newUserWithLink();

    $this->post(route('account-setup.store', $token), [
        'password' => SETUP_PASSWORD,
        'password_confirmation' => SETUP_PASSWORD,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    $user->refresh();

    expect(Hash::check(SETUP_PASSWORD, $user->password))->toBeTrue()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(AccountSetupToken::query()->sole()->used_at)->not->toBeNull()
        ->and(LoginHistory::query()->where('event', LoginEvent::SetupCompleted)->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a link works only once', function () {
    [$user, $url, $token] = newUserWithLink();
    $data = ['password' => SETUP_PASSWORD, 'password_confirmation' => SETUP_PASSWORD];

    $this->post(route('account-setup.store', $token), $data);
    auth()->logout();

    assertGenericInvalidPage($this->get($url));
    assertGenericInvalidPage($this->post(route('account-setup.store', $token), ['password' => 'Another-Password-9!', 'password_confirmation' => 'Another-Password-9!']));

    expect(Hash::check(SETUP_PASSWORD, $user->fresh()->password))->toBeTrue();
});

test('expired, used, unknown and inactive-account links all show the same generic page', function () {
    [$user, $url, $token] = newUserWithLink();

    // Unknown token.
    assertGenericInvalidPage($this->get(route('account-setup.show', str_repeat('x', 64))));

    // Expired.
    $this->travel(AccountSetupToken::VALID_HOURS + 1)->hours();
    assertGenericInvalidPage($this->get($url));
    $this->travelBack();

    // Inactive account.
    $user->forceFill(['is_active' => false])->save();
    assertGenericInvalidPage($this->get($url));
});

test('issuing a new link invalidates the previous one', function () {
    [$user, $oldUrl] = newUserWithLink();

    $newUrl = app(IssueAccountSetupLink::class)($user);

    assertGenericInvalidPage($this->get($oldUrl));
    $this->get($newUrl)->assertInertia(fn (Assert $page) => $page->component('auth/account-setup'));
});

test('only the SHA-256 hash of the token is stored', function () {
    [, , $token] = newUserWithLink();

    $row = DB::table('account_setup_tokens')->sole();

    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(DB::table('account_setup_tokens')->get()))->not->toContain($token)
        ->and(strlen($token))->toBe(64);
});

test('links expire after 72 hours', function () {
    newUserWithLink();

    expect(AccountSetupToken::query()->sole()->expires_at->diffInHours(now(), true))->toBeGreaterThan(71.9)->toBeLessThan(72.1);
});

test('the setup password must follow the policy, and a rejected password leaves the link unused', function () {
    [$user, , $token] = newUserWithLink();

    $this->post(route('account-setup.store', $token), ['password' => 'weakpassword', 'password_confirmation' => 'weakpassword'])
        ->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(AccountSetupToken::query()->sole()->used_at)->toBeNull();
});

test('email delivery sends the link to the user', function () {
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();

    app(SendAccountSetupLink::class)($user);

    Notification::assertSentTo($user, AccountSetupNotification::class, fn (AccountSetupNotification $n) => str_contains($n->url, '/account/setup/'));
});

test('CreateUser does not issue or send a link by itself', function () {
    Notification::fake();

    app(CreateUser::class)(['name' => 'X', 'email' => 'x@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral));

    expect(AccountSetupToken::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('links cannot be issued to inactive accounts', function () {
    $user = User::factory()->directorGeneral()->inactive()->create();

    app(IssueAccountSetupLink::class)($user);
})->throws(ValidationException::class);

test('the setup route is rate limited per IP', function () {
    $url = route('account-setup.show', str_repeat('y', 64));

    foreach (range(1, 10) as $i) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertTooManyRequests();
});
