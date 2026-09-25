<?php

use App\Enums\JurisdictionLevel;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the database rejects inconsistent jurisdiction combinations', function (string $level, bool $withState, bool $withLga) {
    $lga = Lga::factory()->create();

    // Raw insert: tests the database constraint itself, not the model.
    expect(fn () => DB::table('users')->insert([
        'ulid' => strtolower((string) Str::ulid()),
        'name' => 'Constraint Test',
        'email' => Str::random(8).'@example.test',
        'password' => 'x',
        'jurisdiction_level' => $level,
        'state_id' => $withState ? $lga->state_id : null,
        'lga_id' => $withLga ? $lga->id : null,
    ]))->toThrow(QueryException::class, 'users_jurisdiction_consistency_check');
})->with([
    'system with a state' => ['system', true, false],
    'national with a state' => ['national', true, false],
    'national with an LGA' => ['national', true, true],
    'state without a state' => ['state', false, false],
    'state with an LGA' => ['state', true, true],
    'lga without an LGA' => ['lga', true, false],
    'lga without a state' => ['lga', false, true],
    'unknown level' => ['galaxy', false, false],
]);

test('the database accepts every valid combination', function () {
    $lga = Lga::factory()->create();

    foreach ([
        ['system', null, null],
        ['national', null, null],
        ['state', $lga->state_id, null],
        ['lga', $lga->state_id, $lga->id],
    ] as [$level, $state, $lgaId]) {
        expect(User::factory()->create(['jurisdiction_level' => $level, 'state_id' => $state, 'lga_id' => $lgaId])->jurisdiction_level)
            ->toBe(JurisdictionLevel::from($level));
    }
});

test('a user LGA must belong to the user state', function () {
    $lga = Lga::factory()->create();
    $otherState = State::factory()->create();

    expect(fn () => User::factory()->create([
        'jurisdiction_level' => 'lga',
        'state_id' => $otherState->id,
        'lga_id' => $lga->id,
    ]))->toThrow(QueryException::class, 'users_lga_same_state_foreign');
});

test('user JSON never contains ids, secrets or two-factor fields', function () {
    $user = User::factory()->lgaChief()->withTwoFactor()->create(['service_number' => 'SN-1']);
    $user->load('roles', 'state', 'lga', 'zones');

    $json = $user->toJson();
    $keys = array_keys($user->toArray());

    foreach (['id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'failed_login_count', 'state_id', 'lga_id', 'created_by', 'updated_by', 'deactivated_by', 'roles', 'permissions'] as $hidden) {
        expect($keys)->not->toContain($hidden);
    }

    expect($json)->not->toContain('"id"')
        ->not->toContain('$2y$')
        ->toContain($user->ulid);
});

test('users route by ULID', function () {
    $user = User::factory()->create();

    expect($user->getRouteKeyName())->toBe('ulid')
        ->and($user->getRouteKey())->toBe($user->ulid);
});
