<?php

use App\Models\Lga;
use App\Models\State;
use App\Support\Jurisdiction\JurisdictionOptions;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->jurisdictionOptions = app(JurisdictionOptions::class);
});

/**
 * @param  iterable<State|Lga>  $models
 * @param  array<string, State|Lga>  $named
 * @return list<string>
 */
function optionNames(iterable $models, array $named): array
{
    $ids = collect($models)->map->getKey()->all();

    return collect($named)->filter(fn ($model) => in_array($model->getKey(), $ids, true))->keys()->sort()->values()->all();
}

test('selectable states and LGAs follow the jurisdiction', function (string $who, array $states, array $lgas) {
    $user = $this->world->users[$who];

    expect(optionNames($this->jurisdictionOptions->states($user), $this->world->states))->toBe($states)
        ->and(optionNames($this->jurisdictionOptions->lgas($user), $this->world->lgas))->toBe($lgas);
})->with([
    'DG' => ['director_general', ['A1', 'A2', 'B1'], ['A1a', 'A1b', 'A2', 'B1']],
    'DDG zone A' => ['ddg_zone_a', ['A1', 'A2'], ['A1a', 'A1b', 'A2']],
    'state director' => ['state_director', ['A1'], ['A1a', 'A1b']],
    'LGA chief' => ['lga_chief', ['A1'], ['A1a']],
    'super admin' => ['super_admin', [], []],
    'inactive DG' => ['inactive_dg', [], []],
]);

test('LGAs can be narrowed to one state and inactive places are excluded', function () {
    $this->world->lgas['A1b']->update(['is_active' => false]);
    $dg = $this->world->users['director_general'];

    expect(optionNames($this->jurisdictionOptions->lgas($dg, $this->world->states['A1']), $this->world->lgas))->toBe(['A1a'])
        ->and(optionNames($this->jurisdictionOptions->lgas($this->world->users['state_director'], $this->world->states['B1']), $this->world->lgas))->toBe([]);
});
