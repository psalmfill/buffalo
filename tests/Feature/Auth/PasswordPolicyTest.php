<?php

use App\Actions\Auth\SetUserPassword;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Support\Auth\PasswordRules;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

dataset('weak passwords', [
    'too short' => ['Short-Pw-1!'],
    'no upper case' => ['lower-case-only-1!'],
    'no lower case' => ['UPPER-CASE-ONLY-1!'],
    'no number' => ['No-Number-Password!'],
    'no symbol' => ['NoSymbolPassword123'],
]);

function passes(string $password, ?User $user = null): bool
{
    return Validator::make(
        ['password' => $password, 'password_confirmation' => $password],
        ['password' => PasswordRules::rules($user)],
    )->passes();
}

test('the policy rejects weak passwords', function (string $password) {
    expect(passes($password))->toBeFalse();
})->with('weak passwords');

test('the policy accepts a strong password and makes no external requests', function () {
    Http::preventStrayRequests();

    expect(passes('Strong-Password-2026!'))->toBeTrue();
});

test('changing the password in settings enforces the policy', function (string $password) {
    $user = User::factory()->directorGeneral()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ])
        ->assertSessionHasErrors('password');
})->with('weak passwords');

test('resetting a forgotten password enforces the policy', function (string $password) {
    config(['buffalo.mail_enabled' => true]);
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();
    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user, $password) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors('password');

        return true;
    });
})->with('weak passwords');

test('buffalo:create-super-admin enforces the policy', function (string $password) {
    $this->artisan('buffalo:create-super-admin')
        ->expectsQuestion('Full name', 'Ada Admin')
        ->expectsQuestion('Email address', 'weak@buffalo.test')
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $password)
        ->assertFailed();

    expect(User::query()->where('email', 'weak@buffalo.test')->exists())->toBeFalse();
})->with('weak passwords');

test('the last five passwords cannot be reused and only five are kept', function () {
    $user = User::factory()->directorGeneral()->create();
    $set = app(SetUserPassword::class);

    foreach (range(1, 6) as $n) {
        $set($user, "History-Password-{$n}!");
    }

    $user->refresh();

    // Passwords 2..6 are the last five (6 is current); 1 may be reused.
    foreach (range(2, 6) as $n) {
        expect(passes("History-Password-{$n}!", $user))->toBeFalse("password {$n} should be rejected");
    }

    expect(passes('History-Password-1!', $user))->toBeTrue()
        ->and(PasswordHistory::query()->where('user_id', $user->id)->count())->toBe(5);
});

test('changing the password in settings rejects a recent password', function () {
    $user = User::factory()->directorGeneral()->create();
    app(SetUserPassword::class)($user, 'Earlier-Password-1!');
    app(SetUserPassword::class)($user, 'Current-Password-2!');

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'Current-Password-2!',
            'password' => 'Earlier-Password-1!',
            'password_confirmation' => 'Earlier-Password-1!',
        ])
        ->assertSessionHasErrors(['password' => 'You cannot reuse one of your last 5 passwords.']);
});

test('every password change sets password_changed_at and records history', function () {
    $user = User::factory()->directorGeneral()->create(['password_changed_at' => null]);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'Brand-New-Password-7!',
            'password_confirmation' => 'Brand-New-Password-7!',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->password_changed_at)->not->toBeNull()
        ->and($user->passwordHistories()->count())->toBe(1);
});

test('password hashes are never serialised', function () {
    $user = User::factory()->directorGeneral()->create();
    app(SetUserPassword::class)($user, 'Hidden-Password-1!');

    expect($user->passwordHistories()->first()->toArray())->toBe(['created_at' => $user->passwordHistories()->first()->created_at?->toJSON()]);
});
