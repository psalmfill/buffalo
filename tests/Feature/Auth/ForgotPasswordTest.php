<?php

use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('forgot password gives the same answer for active, inactive and unknown addresses', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();
    $active = User::factory()->directorGeneral()->create();
    $inactive = User::factory()->directorGeneral()->inactive()->create();

    foreach ([$active->email, $inactive->email, 'nobody@buffalo.test'] as $email) {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', GenericPasswordResetLinkResponse::MESSAGE)
            ->assertSessionHasNoErrors();
    }

    Notification::assertSentTo($active, ResetPassword::class);
    Notification::assertNotSentTo($inactive, ResetPassword::class);
    Notification::assertCount(1);
});

test('a throttled request gets the same answer', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', GenericPasswordResetLinkResponse::MESSAGE)
        ->assertSessionHasNoErrors();
});

test('no reset email is sent when mail is not configured', function () {
    config(['mail.default' => 'log']);
    Notification::fake();
    $user = User::factory()->directorGeneral()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', GenericPasswordResetLinkResponse::MESSAGE);

    Notification::assertNothingSent();
});

test('the reset page shows the password rules', function () {
    $this->get(route('password.reset', ['token' => 'any', 'email' => 'x@buffalo.test']))
        ->assertInertia(fn ($page) => $page->component('auth/reset-password')->has('passwordRequirements', 5));
});
