<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UserJurisdiction;
use App\Enums\Role;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

test('the posting history follows create, jurisdiction change, deactivate and reactivate', function () {
    $lgaA = Lga::factory()->create();
    $lgaB = Lga::factory()->create();

    $this->travelTo(now()->setDate(2026, 1, 1));
    $user = app(CreateUser::class)(['name' => 'Chief', 'email' => 'chief@buffalo.test'], new UserJurisdiction(Role::LgaSecurityChief, lga: $lgaA), $this->admin);

    $this->travelTo(now()->setDate(2026, 3, 1));
    app(UpdateUserJurisdiction::class)($user, new UserJurisdiction(Role::LgaSecurityChief, lga: $lgaB), $this->admin);

    $this->travelTo(now()->setDate(2026, 5, 1));
    app(DeactivateUser::class)($user->fresh(), 'Suspended', $this->admin);

    $this->travelTo(now()->setDate(2026, 6, 1));
    app(ReactivateUser::class)($user->fresh(), 'Cleared', $this->admin);

    $postings = OfficerPosting::query()->where('user_id', $user->id)->orderBy('started_at')->get();

    expect($postings)->toHaveCount(3)
        ->and($postings->map(fn (OfficerPosting $p) => [$p->lga_id, $p->started_at->toDateString(), $p->ended_at?->toDateString()])->all())
        ->toBe([
            [$lgaA->id, '2026-01-01', '2026-03-01'],
            [$lgaB->id, '2026-03-01', '2026-05-01'],
            [$lgaB->id, '2026-06-01', null],
        ])
        ->and($postings->every(fn (OfficerPosting $p) => $p->role === Role::LgaSecurityChief))->toBeTrue();
});

test('an unchanged jurisdiction does not create a new posting', function () {
    $user = app(CreateUser::class)(['name' => 'DG', 'email' => 'dg@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral), $this->admin);

    app(UpdateUserJurisdiction::class)($user, new UserJurisdiction(Role::DirectorGeneral), $this->admin);

    expect(OfficerPosting::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('the database allows only one open posting per user', function () {
    $user = app(CreateUser::class)(['name' => 'DG', 'email' => 'dg@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral), $this->admin);

    expect(fn () => DB::table('officer_postings')->insert([
        'ulid' => strtolower((string) Str::ulid()),
        'user_id' => $user->id,
        'role' => 'director_general',
        'jurisdiction_level' => 'national',
        'started_at' => now(),
    ]))->toThrow(QueryException::class, 'officer_postings_one_open_per_user');
});

test('holderOn returns who held a role in a place on a given date', function () {
    $lga = Lga::factory()->create();
    $state = State::query()->findOrFail($lga->state_id);

    $this->travelTo(now()->setDate(2025, 1, 1));
    $first = app(CreateUser::class)(['name' => 'First', 'email' => 'first@buffalo.test'], new UserJurisdiction(Role::LgaSecurityChief, lga: $lga), $this->admin);
    $director = app(CreateUser::class)(['name' => 'Director', 'email' => 'director@buffalo.test'], new UserJurisdiction(Role::StateDirector, state: $state), $this->admin);

    $this->travelTo(now()->setDate(2025, 7, 1));
    app(DeactivateUser::class)($first->fresh(), 'Transferred', $this->admin);
    $second = app(CreateUser::class)(['name' => 'Second', 'email' => 'second@buffalo.test'], new UserJurisdiction(Role::LgaSecurityChief, lga: $lga), $this->admin);

    $this->travelTo(now()->setDate(2026, 1, 1));

    expect(OfficerPosting::holderOn($lga, Role::LgaSecurityChief, now()->setDate(2025, 3, 15))?->is($first))->toBeTrue()
        ->and(OfficerPosting::holderOn($lga, Role::LgaSecurityChief, now()->setDate(2025, 9, 1))?->is($second))->toBeTrue()
        ->and(OfficerPosting::holderOn($lga, Role::LgaSecurityChief, now()->setDate(2024, 12, 31)))->toBeNull()
        ->and(OfficerPosting::holderOn($state, Role::StateDirector, now()->setDate(2025, 9, 1))?->is($director))->toBeTrue()
        ->and(OfficerPosting::holderOn($state, Role::LgaSecurityChief, now()->setDate(2025, 9, 1)))->toBeNull();
});

test('postings are jurisdiction-scoped and visible to super admins', function () {
    $lga = Lga::factory()->create();
    $other = Lga::factory()->create();
    app(CreateUser::class)(['name' => 'A', 'email' => 'a@buffalo.test'], new UserJurisdiction(Role::LgaSecurityChief, lga: $lga), $this->admin);
    app(CreateUser::class)(['name' => 'B', 'email' => 'b@buffalo.test'], new UserJurisdiction(Role::LgaSecurityChief, lga: $other), $this->admin);
    $director = User::factory()->stateDirector(State::query()->findOrFail($lga->state_id))->create();

    $context = app(JurisdictionContext::class);

    expect($context->actingAs($this->admin, fn () => OfficerPosting::query()->where('role', 'lga_security_chief')->count()))->toBe(2)
        ->and($context->actingAs($director, fn () => OfficerPosting::query()->where('role', 'lga_security_chief')->pluck('lga_id')->all()))->toBe([$lga->id]);
});
