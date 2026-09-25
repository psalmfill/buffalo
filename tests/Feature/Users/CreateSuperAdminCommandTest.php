<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function runCreateSuperAdmin(object $test, string $email, string $password = 'Correct-Horse-Battery-9', ?string $confirmation = null)
{
    return $test->artisan('buffalo:create-super-admin')
        ->expectsQuestion('Full name', 'Ada Admin')
        ->expectsQuestion('Email address', $email)
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $confirmation ?? $password);
}

test('it creates a working super admin on a fresh database', function () {
    // Simulate a fresh database: no roles or permissions yet.
    SpatieRole::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    runCreateSuperAdmin($this, 'Admin@Buffalo.test')
        ->expectsOutputToContain('Super admin admin@buffalo.test created.')
        ->expectsOutputToContain('They must enrol in two-factor authentication at first login.')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@buffalo.test')->sole();

    expect($admin->getRole())->toBe(Role::SuperAdmin)
        ->and($admin->roles)->toHaveCount(1)
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->password_changed_at)->not->toBeNull()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->checkPermissionTo(Permission::UsersManage->value))->toBeTrue()
        ->and(Auth::attempt(['email' => 'admin@buffalo.test', 'password' => 'Correct-Horse-Battery-9']))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});

test('it refuses an email that already exists, including soft-deleted users', function () {
    User::factory()->create(['email' => 'taken@buffalo.test']);
    tap(User::factory()->create(['email' => 'gone@buffalo.test']))->delete();

    foreach (['taken@buffalo.test', 'TAKEN@buffalo.test', 'gone@buffalo.test'] as $email) {
        $this->artisan('buffalo:create-super-admin')
            ->expectsQuestion('Full name', 'Ada Admin')
            ->expectsQuestion('Email address', $email)
            ->expectsOutputToContain('already exists. Nothing was created.')
            ->assertFailed();
    }

    expect(User::query()->role(Role::SuperAdmin->value)->count())->toBe(0);
});

test('it rejects short or mismatched passwords', function () {
    runCreateSuperAdmin($this, 'short@buffalo.test', 'short-pass')->assertFailed();
    runCreateSuperAdmin($this, 'mismatch@buffalo.test', 'Correct-Horse-Battery-9', 'Different-Horse-Battery-9')->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('no seeder creates users', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->seed(DatabaseSeeder::class);

    expect(User::withTrashed()->count())->toBe(0);
});
