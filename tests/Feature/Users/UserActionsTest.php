<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UserJurisdiction;
use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function createUserWith(UserJurisdiction $jurisdiction, array $data = []): User
{
    return app(CreateUser::class)(
        array_merge(['name' => 'Test Officer', 'email' => fake()->unique()->safeEmail()], $data),
        $jurisdiction,
    );
}

test('CreateUser creates a valid user for every role', function () {
    $lga = Lga::factory()->create();
    $state = State::factory()->create();
    $zone = GeopoliticalZone::factory()->create();

    $cases = [
        [new UserJurisdiction(Role::SuperAdmin), JurisdictionLevel::System, null, null],
        [new UserJurisdiction(Role::DirectorGeneral), JurisdictionLevel::National, null, null],
        [new UserJurisdiction(Role::DeputyDirectorGeneral, zones: [$zone]), JurisdictionLevel::National, null, null],
        [new UserJurisdiction(Role::StateDirector, state: $state), JurisdictionLevel::State, $state->id, null],
        [new UserJurisdiction(Role::DeputyStateDirector, state: $state), JurisdictionLevel::State, $state->id, null],
        [new UserJurisdiction(Role::LgaSecurityChief, lga: $lga), JurisdictionLevel::Lga, $lga->state_id, $lga->id],
    ];

    foreach ($cases as [$jurisdiction, $level, $stateId, $lgaId]) {
        $user = createUserWith($jurisdiction);

        expect($user->getRole())->toBe($jurisdiction->role)
            ->and($user->roles)->toHaveCount(1)
            ->and($user->jurisdiction_level)->toBe($level)
            ->and($user->state_id)->toBe($stateId)
            ->and($user->lga_id)->toBe($lgaId)
            ->and($user->is_active)->toBeTrue()
            ->and(Str::isUlid($user->ulid))->toBeTrue();
    }

    expect(User::query()->where('email', '!=', '')->whereHas('zones')->sole()->zones->modelKeys())->toBe([$zone->id]);
});

test('CreateUser rejects role and jurisdiction mismatches', function (Closure $jurisdiction, string $field) {
    $before = User::withTrashed()->count();

    try {
        createUserWith($jurisdiction());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(User::withTrashed()->count())->toBe($before);
})->with([
    'super admin with a state' => [fn () => new UserJurisdiction(Role::SuperAdmin, state: State::factory()->create()), 'state'],
    'DG with an LGA' => [fn () => new UserJurisdiction(Role::DirectorGeneral, lga: Lga::factory()->create()), 'lga'],
    'state director without a state' => [fn () => new UserJurisdiction(Role::StateDirector), 'state'],
    'deputy state director with an LGA' => [fn () => new UserJurisdiction(Role::DeputyStateDirector, state: State::factory()->create(), lga: Lga::factory()->create()), 'lga'],
    'LGA chief without an LGA' => [fn () => new UserJurisdiction(Role::LgaSecurityChief, state: State::factory()->create()), 'lga'],
    'LGA chief with an LGA of another state' => [fn () => new UserJurisdiction(Role::LgaSecurityChief, state: State::factory()->create(), lga: Lga::factory()->create()), 'lga'],
    'LGA chief in an inactive LGA' => [fn () => new UserJurisdiction(Role::LgaSecurityChief, lga: Lga::factory()->inactive()->create()), 'lga'],
    'zones on a state director' => [fn () => new UserJurisdiction(Role::StateDirector, state: State::factory()->create(), zones: [GeopoliticalZone::factory()->create()]), 'zones'],
    'zones on a DG' => [fn () => new UserJurisdiction(Role::DirectorGeneral, zones: [GeopoliticalZone::factory()->create()]), 'zones'],
    'zones on a super admin' => [fn () => new UserJurisdiction(Role::SuperAdmin, zones: [GeopoliticalZone::factory()->create()]), 'zones'],
]);

test('CreateUser rejects duplicate emails (case-insensitively) and service numbers', function () {
    createUserWith(new UserJurisdiction(Role::DirectorGeneral), ['email' => 'officer@buffalo.test', 'service_number' => 'SN-1']);

    expect(fn () => createUserWith(new UserJurisdiction(Role::DirectorGeneral), ['email' => 'OFFICER@buffalo.test']))
        ->toThrow(ValidationException::class)
        ->and(fn () => createUserWith(new UserJurisdiction(Role::DirectorGeneral), ['service_number' => 'SN-1']))
        ->toThrow(ValidationException::class);
});

test('a created user has an unusable random password', function () {
    $user = createUserWith(new UserJurisdiction(Role::DirectorGeneral));

    expect(Hash::check('password', $user->password))->toBeFalse()
        ->and(Hash::check('', $user->password))->toBeFalse()
        ->and(auth()->attempt(['email' => $user->email, 'password' => 'password']))->toBeFalse();
});

test('CreateUser records the creator', function () {
    $admin = User::factory()->superAdmin()->create();

    $user = app(CreateUser::class)(['name' => 'X', 'email' => 'x@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral), $admin);

    expect($user->creator?->is($admin))->toBeTrue();
});

test('UpdateUserJurisdiction changes role and jurisdiction, keeping one role', function () {
    $zone = GeopoliticalZone::factory()->create();
    $user = createUserWith(new UserJurisdiction(Role::DeputyDirectorGeneral, zones: [$zone]));
    $lga = Lga::factory()->create();

    app(UpdateUserJurisdiction::class)($user, new UserJurisdiction(Role::LgaSecurityChief, lga: $lga));
    $user->refresh();

    expect($user->roles->pluck('name')->all())->toBe(['lga_security_chief'])
        ->and($user->jurisdiction_level)->toBe(JurisdictionLevel::Lga)
        ->and($user->lga_id)->toBe($lga->id)
        ->and($user->state_id)->toBe($lga->state_id)
        ->and($user->zones)->toHaveCount(0);
});

test('UpdateUserJurisdiction rejects invalid combinations without changing the user', function () {
    $user = createUserWith(new UserJurisdiction(Role::DirectorGeneral));

    expect(fn () => app(UpdateUserJurisdiction::class)($user, new UserJurisdiction(Role::StateDirector)))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->getRole())->toBe(Role::DirectorGeneral)
        ->and($user->fresh()->jurisdiction_level)->toBe(JurisdictionLevel::National);
});

test('the last active super admin cannot be given another role', function () {
    $admin = User::factory()->superAdmin()->create();

    expect(fn () => app(UpdateUserJurisdiction::class)($admin, new UserJurisdiction(Role::DirectorGeneral)))
        ->toThrow(ValidationException::class, 'The last active super admin');

    User::factory()->superAdmin()->create();
    app(UpdateUserJurisdiction::class)($admin, new UserJurisdiction(Role::DirectorGeneral));

    expect($admin->fresh()->getRole())->toBe(Role::DirectorGeneral);
});

test('DeactivateUser requires a reason, refuses self-deactivation and records who and why', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = User::factory()->directorGeneral()->create();
    $deactivate = app(DeactivateUser::class);

    expect(fn () => $deactivate($user, '   ', $admin))->toThrow(ValidationException::class)
        ->and(fn () => $deactivate($admin, 'Leaving', $admin))->toThrow(ValidationException::class, 'your own account');

    $deactivate($user, 'Retired', $admin);
    $user->refresh();

    expect($user->is_active)->toBeFalse()
        ->and($user->deactivation_reason)->toBe('Retired')
        ->and($user->deactivator?->is($admin))->toBeTrue()
        ->and($user->deactivated_at)->not->toBeNull()
        ->and(User::withTrashed()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->trashed())->toBeFalse();

    expect(fn () => $deactivate($user, 'Again', $admin))->toThrow(ValidationException::class, 'already deactivated');
});

test('the last active super admin cannot be deactivated', function () {
    $admin = User::factory()->superAdmin()->create();

    expect(fn () => app(DeactivateUser::class)($admin, 'Test'))
        ->toThrow(ValidationException::class, 'The last active super admin');

    $other = User::factory()->superAdmin()->create();
    app(DeactivateUser::class)($admin, 'Test', $other);

    expect($admin->fresh()->is_active)->toBeFalse();
});

test('ReactivateUser clears deactivation and locks', function () {
    $user = User::factory()->directorGeneral()->inactive()->locked()->create(['failed_login_count' => 5]);

    app(ReactivateUser::class)($user);
    $user->refresh();

    expect($user->is_active)->toBeTrue()
        ->and($user->deactivated_at)->toBeNull()
        ->and($user->deactivation_reason)->toBeNull()
        ->and($user->locked_until)->toBeNull()
        ->and($user->failed_login_count)->toBe(0)
        ->and($user->isAccessBlocked())->toBeFalse();

    expect(fn () => app(ReactivateUser::class)($user))->toThrow(ValidationException::class, 'already active');
});
