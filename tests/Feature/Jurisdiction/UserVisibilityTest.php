<?php

use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionGate;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
});

/**
 * @return list<string> names of world users visible to $who
 */
function visibleWorldUsers(JurisdictionWorld $world, string $who): array
{
    $ulids = app(JurisdictionContext::class)->actingAs(
        User::withTrashed()->findOrFail($world->users[$who]->id),
        fn () => User::query()->pluck('ulid')->all(),
    );

    return collect($world->users)
        ->filter(fn (User $user) => in_array($user->ulid, $ulids, true))
        ->keys()
        ->sort()
        ->values()
        ->all();
}

test('super admins and the DG see all (non-deleted) users', function (string $who) {
    $all = collect($this->world->users)->reject(fn (User $u, string $k) => $k === 'deleted_dg')->keys()->sort()->values()->all();

    expect(visibleWorldUsers($this->world, $who))->toBe($all);
})->with(['super_admin', 'director_general', 'ddg_national']);

test('a state director sees only users in their state', function () {
    expect(visibleWorldUsers($this->world, 'state_director'))
        ->toBe(['deputy_state_director', 'lga_chief', 'state_director']);
});

test('an LGA chief sees only users in their LGA', function () {
    expect(visibleWorldUsers($this->world, 'lga_chief'))->toBe(['lga_chief']);
});

test('a zone-restricted DDG sees users in the states of their zones only', function () {
    $zoneB = User::factory()->stateDirector($this->world->states['B1'])->create();

    $visible = app(JurisdictionContext::class)->actingAs(
        $this->world->users['ddg_zone_a'],
        fn () => User::query()->pluck('ulid')->all(),
    );

    expect($visible)->toContain($this->world->users['state_director']->ulid, $this->world->users['lga_chief']->ulid)
        ->not->toContain($zoneB->ulid, $this->world->users['director_general']->ulid, $this->world->users['super_admin']->ulid);
});

test('blocked and role-less users see no users', function (string $who) {
    expect(visibleWorldUsers($this->world, $who))->toBe([]);
})->with(['inactive_dg', 'locked_dg', 'deleted_dg', 'no_role', 'inconsistent_state_director']);

test('JurisdictionGate agrees with the scope for users', function () {
    $gate = app(JurisdictionGate::class);

    foreach (['super_admin', 'state_director', 'lga_chief', 'ddg_zone_a', 'inactive_dg'] as $who) {
        $viewer = $this->world->users[$who];
        $visible = visibleWorldUsers($this->world, $who);

        foreach ($this->world->users as $name => $target) {
            if ($name === 'deleted_dg') {
                continue;
            }

            expect($gate->canAccess($viewer, $target))->toBe(in_array($name, $visible, true), "{$who} -> {$name}");
        }
    }
});
