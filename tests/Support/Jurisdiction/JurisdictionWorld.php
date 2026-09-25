<?php

namespace Tests\Support\Jurisdiction;

use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;

/**
 * The scenario behind the jurisdiction matrix tests.
 *
 *   Zone A: state A1 (LGAs A1a, A1b), state A2 (LGA A2)
 *   Zone B: state B1 (LGA B1)
 *   Zone E: no states
 *
 * Records (ScopedFixtureRecord), seen from the LGA chief of A1a:
 *   own_lga     A1 / A1a
 *   same_state  A1 / A1b
 *   state_level A1 / (no LGA)
 *   same_zone   A2 / A2
 *   other_zone  B1 / B1
 */
final class JurisdictionWorld
{
    public const RECORDS = ['own_lga', 'same_state', 'state_level', 'same_zone', 'other_zone'];

    /**
     * Expected visible fixture records per user. Written out by hand from
     * CLAUDE.md section 4 and the Phase 1B prompt; not derived from code.
     */
    public const EXPECTED = [
        'super_admin' => [],
        'director_general' => ['own_lga', 'same_state', 'state_level', 'same_zone', 'other_zone'],
        'ddg_national' => ['own_lga', 'same_state', 'state_level', 'same_zone', 'other_zone'],
        'ddg_zone_a' => ['own_lga', 'same_state', 'state_level', 'same_zone'],
        'ddg_zone_b' => ['other_zone'],
        'ddg_zones_a_b' => ['own_lga', 'same_state', 'state_level', 'same_zone', 'other_zone'],
        'ddg_empty_zone' => [],
        'state_director' => ['own_lga', 'same_state', 'state_level'],
        'deputy_state_director' => ['own_lga', 'same_state', 'state_level'],
        'lga_chief' => ['own_lga'],
        'inactive_dg' => [],
        'locked_dg' => [],
        'deleted_dg' => [],
        'no_role' => [],
        'inconsistent_state_director' => [],
    ];

    /** @var array<string, GeopoliticalZone> */
    public array $zones = [];

    /** @var array<string, State> */
    public array $states = [];

    /** @var array<string, Lga> */
    public array $lgas = [];

    /** @var array<string, ScopedFixtureRecord> */
    public array $records = [];

    /** @var array<string, User> */
    public array $users = [];

    public static function build(): self
    {
        ScopedFixtureRecord::createTable();

        $world = new self;

        // Built in system mode so setup queries are not scoped.
        app(JurisdictionContext::class)->system();

        $world->zones['A'] = GeopoliticalZone::factory()->create();
        $world->zones['B'] = GeopoliticalZone::factory()->create();
        $world->zones['E'] = GeopoliticalZone::factory()->create();

        $world->states['A1'] = State::factory()->for($world->zones['A'])->create();
        $world->states['A2'] = State::factory()->for($world->zones['A'])->create();
        $world->states['B1'] = State::factory()->for($world->zones['B'])->create();

        $world->lgas['A1a'] = Lga::factory()->create(['state_id' => $world->states['A1']->id]);
        $world->lgas['A1b'] = Lga::factory()->create(['state_id' => $world->states['A1']->id]);
        $world->lgas['A2'] = Lga::factory()->create(['state_id' => $world->states['A2']->id]);
        $world->lgas['B1'] = Lga::factory()->create(['state_id' => $world->states['B1']->id]);

        foreach ([
            'own_lga' => ['A1', 'A1a'],
            'same_state' => ['A1', 'A1b'],
            'state_level' => ['A1', null],
            'same_zone' => ['A2', 'A2'],
            'other_zone' => ['B1', 'B1'],
        ] as $label => [$state, $lga]) {
            $world->records[$label] = ScopedFixtureRecord::query()->create([
                'label' => $label,
                'state_id' => $world->states[$state]->id,
                'lga_id' => $lga === null ? null : $world->lgas[$lga]->id,
            ]);
        }

        $world->users = [
            'super_admin' => User::factory()->superAdmin()->create(),
            'director_general' => User::factory()->directorGeneral()->create(),
            'ddg_national' => User::factory()->ddg()->create(),
            'ddg_zone_a' => User::factory()->ddg([$world->zones['A']])->create(),
            'ddg_zone_b' => User::factory()->ddg([$world->zones['B']])->create(),
            'ddg_zones_a_b' => User::factory()->ddg([$world->zones['A'], $world->zones['B']])->create(),
            'ddg_empty_zone' => User::factory()->ddg([$world->zones['E']])->create(),
            'state_director' => User::factory()->stateDirector($world->states['A1'])->create(),
            'deputy_state_director' => User::factory()->deputyStateDirector($world->states['A1'])->create(),
            'lga_chief' => User::factory()->lgaChief($world->lgas['A1a'])->create(),
            'inactive_dg' => User::factory()->directorGeneral()->inactive()->create(),
            'locked_dg' => User::factory()->directorGeneral()->locked()->create(),
            'deleted_dg' => tap(User::factory()->directorGeneral()->create())->delete(),
            'no_role' => User::factory()->create(['jurisdiction_level' => JurisdictionLevel::National]),
            'inconsistent_state_director' => tap(
                User::factory()->create(['jurisdiction_level' => JurisdictionLevel::National]),
                fn (User $user) => $user->syncRoles([Role::StateDirector->value]),
            ),
        ];

        return $world;
    }

    /**
     * Fixture record labels visible to the given user through the scope.
     *
     * @return list<string>
     */
    public function visibleRecords(User $user): array
    {
        return app(JurisdictionContext::class)->actingAs(
            $user->fresh() ?? User::withTrashed()->findOrFail($user->id),
            fn () => ScopedFixtureRecord::query()->orderBy('label')->pluck('label')->all(),
        );
    }
}
