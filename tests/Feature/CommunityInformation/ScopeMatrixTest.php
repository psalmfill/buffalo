<?php

use App\Enums\VulnerableCategory;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\ReligiousInstitution;
use App\Models\VulnerablePopulationStat;
use App\Support\Jurisdiction\JurisdictionContext;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Religious institutions, vulnerable figures and (restricted) security
 * notes belong to an LGA: the Phase 1B matrix minus its state-level record.
 */
test('community information follows the jurisdiction matrix', function (string $who) {
    $world = JurisdictionWorld::build();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        $community = Community::factory()->in($world->lgas[$lga])->create();
        ReligiousInstitution::factory()->in($community)->create(['name' => $label]);
        VulnerablePopulationStat::factory()->forLga($world->lgas[$lga])->create(['source' => $label, 'category' => VulnerableCategory::Other]);
        CommunitySecurityNote::factory()->in($community)->create(['title' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $label) => $label !== 'state_level'));
    sort($expected);

    [$institutions, $figures, $notes] = app(JurisdictionContext::class)->actingAs($world->users[$who]->fresh() ?? $world->users[$who], fn () => [
        ReligiousInstitution::query()->orderBy('name')->pluck('name')->all(),
        VulnerablePopulationStat::query()->orderBy('source')->pluck('source')->all(),
        CommunitySecurityNote::query()->orderBy('title')->pluck('title')->all(),
    ]);

    expect($institutions)->toBe($expected)
        ->and($figures)->toBe($expected)
        ->and($notes)->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));
