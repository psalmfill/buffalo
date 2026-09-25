<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

test('registration does not exist', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'Password-12345!',
        'password_confirmation' => 'Password-12345!',
    ])->assertNotFound();

    expect(Route::has('register'))->toBeFalse()
        ->and(User::query()->where('email', 'intruder@example.com')->exists())->toBeFalse();
});

test('passkey routes and the passkeys table do not exist', function () {
    foreach (['/passkeys/login', '/passkeys/login/options', '/user/passkeys', '/user/passkeys/options', '/.well-known/passkey-endpoints'] as $uri) {
        $this->get($uri)->assertNotFound();
        $this->post($uri)->assertNotFound();
    }

    $names = collect(Route::getRoutes()->getRoutesByName())->keys();

    expect($names->filter(fn (string $name) => str_contains($name, 'passkey'))->all())->toBe([])
        ->and(Schema::hasTable('passkeys'))->toBeFalse();
});

test('users cannot edit their profile or delete their account', function () {
    $user = User::factory()->directorGeneral()->create(['name' => 'Original Name']);

    $this->actingAs($user)->get('/settings/profile')->assertNotFound();
    $this->actingAs($user)->patch('/settings/profile', ['name' => 'Changed', 'email' => 'changed@example.com'])->assertNotFound();
    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertNotFound();

    expect($user->fresh()->name)->toBe('Original Name')
        ->and($user->fresh()->trashed())->toBeFalse();
});

test('email verification routes do not exist', function () {
    $user = User::factory()->directorGeneral()->unverified()->create();

    $this->actingAs($user)->get('/email/verify')->assertNotFound();
    $this->actingAs($user)->post('/email/verification-notification')->assertNotFound();
});

test('settings redirects to the security page', function () {
    $this->actingAs(User::factory()->directorGeneral()->create())
        ->get('/settings')
        ->assertRedirect('/settings/security');
});

test('the frontend has no registration, passkey or profile-editing code', function () {
    $offending = collect(File::allFiles(resource_path('js')))
        ->reject(fn ($file) => preg_match('#resources/js/(actions|routes|wayfinder)/#', $file->getPathname()) === 1)
        ->filter(fn ($file) => preg_match('/@\/routes\/(register|profile)|\bregister\(\)|Sign up|passkey|verify-email/i', $file->getContents()) === 1)
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offending)->toBe([])
        ->and(File::exists(resource_path('js/pages/auth/register.tsx')))->toBeFalse()
        ->and(File::exists(resource_path('js/pages/welcome.tsx')))->toBeFalse();
});
