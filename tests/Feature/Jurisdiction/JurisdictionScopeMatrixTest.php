<?php

use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionGate;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Jurisdiction\ScopedFixtureRecord;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
});

test('each user sees exactly the records of their jurisdiction', function (string $who) {
    $expected = JurisdictionWorld::EXPECTED[$who];
    sort($expected);

    expect($this->world->visibleRecords($this->world->users[$who]))->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('JurisdictionGate agrees with the scope for every user and record', function (string $who) {
    $gate = app(JurisdictionGate::class);
    $user = User::withTrashed()->findOrFail($this->world->users[$who]->id);

    foreach (JurisdictionWorld::RECORDS as $label) {
        $record = $this->world->records[$label];

        expect($gate->canAccess($user, $record))
            ->toBe(in_array($label, JurisdictionWorld::EXPECTED[$who], true), "{$who} / {$label}");
    }
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('the none context returns no rows and system returns everything', function () {
    $context = app(JurisdictionContext::class);

    $context->none();
    expect(ScopedFixtureRecord::query()->count())->toBe(0)
        ->and(ScopedFixtureRecord::query()->find($this->world->records['own_lga']->id))->toBeNull();

    $context->system();
    expect(ScopedFixtureRecord::query()->count())->toBe(5);
});

test('an orWhere elsewhere in the query cannot widen the scope', function () {
    $visible = app(JurisdictionContext::class)->actingAs($this->world->users['lga_chief'], fn () => ScopedFixtureRecord::query()
        ->where('label', 'nothing-matches')
        ->orWhere('id', '>', 0)
        ->orWhereNotNull('state_id')
        ->pluck('label')
        ->all());

    expect($visible)->toBe(['own_lga']);
});

test('the scope condition is a nested group in the generated SQL', function () {
    $sql = app(JurisdictionContext::class)->actingAs($this->world->users['state_director'], fn () => ScopedFixtureRecord::query()
        ->where('label', 'x')
        ->orWhere('id', '>', 0)
        ->toSql());

    expect($sql)->toContain('and ("scoped_fixture_records"."state_id" in (');
});

test('aggregates, exists and find respect the scope', function () {
    app(JurisdictionContext::class)->actingAs($this->world->users['state_director'], function () {
        expect(ScopedFixtureRecord::query()->count())->toBe(3)
            ->and(ScopedFixtureRecord::query()->whereKey($this->world->records['other_zone']->id)->exists())->toBeFalse()
            ->and(ScopedFixtureRecord::query()->find($this->world->records['other_zone']->id))->toBeNull()
            ->and(ScopedFixtureRecord::query()->find($this->world->records['same_state']->id))->not->toBeNull();
    });
});

test('an LGA chief sees nothing on a model without an LGA column', function () {
    $stateLevel = new class extends ScopedFixtureRecord
    {
        protected ?string $jurisdictionLgaColumn = null;
    };

    $lgaChief = $this->world->users['lga_chief'];
    $stateDirector = $this->world->users['state_director'];

    expect(app(JurisdictionContext::class)->actingAs($lgaChief, fn () => $stateLevel->newQuery()->count()))->toBe(0)
        ->and(app(JurisdictionContext::class)->actingAs($stateDirector, fn () => $stateLevel->newQuery()->count()))->toBe(3)
        ->and(app(JurisdictionGate::class)->canAccess($lgaChief, $stateLevel->newInstance(['state_id' => $this->world->states['A1']->id], true)))->toBeFalse();
});

test('a user whose role changes to deactivated mid-request loses access at once', function () {
    $dg = $this->world->users['director_general'];
    $context = app(JurisdictionContext::class);
    $context->user($dg);

    expect(ScopedFixtureRecord::query()->count())->toBe(5);

    $dg->forceFill(['is_active' => false]);

    expect(ScopedFixtureRecord::query()->count())->toBe(0);
});

test('the gate rejects models that are not jurisdiction-scoped', function () {
    app(JurisdictionGate::class)->canAccess($this->world->users['director_general'], $this->world->states['A1']);
})->throws(InvalidArgumentException::class);
