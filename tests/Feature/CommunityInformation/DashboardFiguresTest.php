<?php

use App\Enums\Classification;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Enums\VulnerableCategory;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\ReligiousInstitution;
use App\Models\VulnerablePopulationStat;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Active notes at every level in A1a and A1b (state A1, zone A) and B1
 * (zone B), plus resolved ones that never count.
 */
function dashboardWorld(): JurisdictionWorld
{
    $world = JurisdictionWorld::build();

    foreach (['A1a', 'A1b', 'B1'] as $place) {
        $community = Community::factory()->in($world->lgas[$place])->create();

        foreach (Classification::cases() as $level) {
            CommunitySecurityNote::factory()->in($community)->classified($level)->create(['category' => SecurityNoteCategory::LandDispute]);
        }

        CommunitySecurityNote::factory()->in($community)->create(['category' => SecurityNoteCategory::CultActivity]);
        CommunitySecurityNote::factory()->in($community)->create(['status' => SecurityNoteStatus::Resolved, 'resolved_at' => now()->toDateString()]);
        ReligiousInstitution::factory()->in($community)->create();
    }

    return $world;
}

test('the LGA chief counts only restricted active notes and institutions of their LGA', function () {
    $world = dashboardWorld();

    $this->actingAs($world->users['lga_chief'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('information.activeSecurityNotes', 2)
        ->where('information.religiousInstitutions', 1));
});

test('state dashboards count active notes by LGA within the state and clearance', function () {
    $world = dashboardWorld();

    // Deputy: restricted + confidential (+ cult) = 3 per LGA; not secret.
    $this->actingAs($world->users['deputy_state_director'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        // Equal counts: order between the two LGAs follows their names.
        ->where('securityNotesByLga', fn ($rows) => collect($rows)->pluck('active', 'ulid')->sortKeys()->all() === collect([
            $world->lgas['A1a']->ulid => 3,
            $world->lgas['A1b']->ulid => 3,
        ])->sortKeys()->all()));

    $this->flushSession();
    $this->actingAs($world->users['state_director'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('securityNotesByLga', fn ($rows) => collect($rows)->sum('active') === 8 && count($rows) === 2));
});

test('state vulnerable totals sum the latest LGA-wide figures of the state only', function () {
    $world = JurisdictionWorld::build();
    $idp = ['category' => VulnerableCategory::InternallyDisplaced, 'adult_male' => 1, 'adult_female' => 1, 'child_male' => 1, 'child_female' => 1];
    VulnerablePopulationStat::factory()->forLga($world->lgas['A1a'])->create([...$idp, 'as_of_date' => '2026-01-01', 'adult_male' => 100]);
    VulnerablePopulationStat::factory()->forLga($world->lgas['A1a'])->create([...$idp, 'as_of_date' => '2026-05-01']);
    VulnerablePopulationStat::factory()->forLga($world->lgas['A1b'])->create([...$idp, 'as_of_date' => '2026-02-01']);
    // A community breakdown and another state's figure are not added.
    VulnerablePopulationStat::factory()->forCommunity(Community::factory()->in($world->lgas['A1a'])->create())->create([...$idp, 'as_of_date' => '2026-05-01']);
    VulnerablePopulationStat::factory()->forLga($world->lgas['B1'])->create([...$idp, 'as_of_date' => '2026-05-01']);

    $this->actingAs($world->users['state_director'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('vulnerableTotals', [[
            'category' => 'internally_displaced', 'label' => 'Internally displaced',
            'adult_male' => 2, 'adult_female' => 2, 'child_male' => 2, 'child_female' => 2, 'total' => 8,
        ]]));
});

test('national dashboards count active notes by state and category within zones', function () {
    $world = dashboardWorld();
    $a1 = $world->states['A1']->name;
    $b1 = $world->states['B1']->name;

    $this->actingAs($world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('securityNotesByState.rows', fn ($rows) => collect($rows)->pluck('total', 'state')->all() === [$a1 => 8, $b1 => 4])
        ->where('securityNotesByState.categories', fn ($categories) => collect($categories)->pluck('value')->all() === ['land_dispute', 'cult_activity']));

    $this->flushSession();
    $this->actingAs($world->users['ddg_zone_b'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('securityNotesByState.rows', fn ($rows) => collect($rows)->pluck('total', 'state')->all() === [$b1 => 4]
            && collect($rows)->first()['byCategory'] === ['cult_activity' => 1, 'land_dispute' => 3]));
});
