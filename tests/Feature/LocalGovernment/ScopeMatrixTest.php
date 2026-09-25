<?php

use App\Models\Community;
use App\Models\LgaProfile;
use App\Support\Jurisdiction\JurisdictionContext;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Communities and LGA profiles belong to an LGA, so the Phase 1B matrix
 * applies minus its state-level record.
 */
const LGA_PLACES = [
    'own_lga' => 'A1a',
    'same_state' => 'A1b',
    'same_zone' => 'A2',
    'other_zone' => 'B1',
];

function lgaLevelWorld(): JurisdictionWorld
{
    $world = JurisdictionWorld::build();

    foreach (LGA_PLACES as $label => $lga) {
        Community::factory()->in($world->lgas[$lga])->create(['name' => $label]);
        LgaProfile::factory()->forLga($world->lgas[$lga])->create(['general_notes' => $label]);
    }

    return $world;
}

test('communities and LGA profiles follow the jurisdiction matrix', function (string $who) {
    $world = lgaLevelWorld();
    $user = $world->users[$who];
    $expected = array_values(array_filter(
        JurisdictionWorld::EXPECTED[$who],
        fn (string $label) => $label !== 'state_level',
    ));
    sort($expected);

    [$communities, $profiles] = app(JurisdictionContext::class)->actingAs($user->fresh() ?? $user, fn () => [
        Community::query()->orderBy('name')->pluck('name')->all(),
        LgaProfile::query()->orderBy('general_notes')->pluck('general_notes')->all(),
    ]);

    expect($communities)->toBe($expected)
        ->and($profiles)->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('the super admin sees no communities or profiles', function () {
    $world = lgaLevelWorld();

    expect(app(JurisdictionContext::class)->actingAs($world->users['super_admin'], fn () => Community::query()->count() + LgaProfile::query()->count()))->toBe(0);
});
