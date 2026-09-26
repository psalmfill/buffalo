<?php

use App\Enums\VulnerableCategory;
use App\Models\Community;
use App\Models\VulnerablePopulationStat;
use App\Support\CommunityInformation\TabData;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;

function figuresPayload(array $overrides = []): array
{
    return [
        'category' => 'internally_displaced',
        'adult_male' => 10, 'adult_female' => 12, 'child_male' => 5, 'child_female' => 7,
        'as_of_date' => now()->subDays(3)->toDateString(),
        'source' => 'LGA social welfare office',
        ...$overrides,
    ];
}

test('the table holds aggregate counts only: no notes or name columns', function () {
    expect(Schema::getColumnListing('vulnerable_population_stats'))->toEqualCanonicalizing([
        'id', 'ulid', 'state_id', 'lga_id', 'community_id', 'category',
        'adult_male', 'adult_female', 'child_male', 'child_female',
        'as_of_date', 'source', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ]);
});

test('figures are unique per LGA or community, category and date, LGA-wide included', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $this->actingAs($world->users['lga_chief']);

    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload())->assertSessionHasNoErrors();
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload())->assertSessionHasErrors('as_of_date');
    // Same category and date for a community is a different series.
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['community' => $community->ulid]))->assertSessionHasNoErrors();
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['community' => $community->ulid]))->assertSessionHasErrors('as_of_date');

    // The database treats two LGA-wide (null community) rows as duplicates.
    app(JurisdictionContext::class)->system();
    expect(fn () => DB::transaction(fn () => VulnerablePopulationStat::factory()->forLga($lga)->create(['as_of_date' => now()->subDays(3)->toDateString()])))
        ->toThrow(QueryException::class);
});

test('future dates, negative counts and communities of other LGAs are rejected', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $this->actingAs($world->users['state_director']);

    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['as_of_date' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors(['as_of_date' => 'The date cannot be in the future.']);
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['child_female' => -1]))->assertSessionHasErrors('child_female');
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['community' => Community::factory()->in($world->lgas['A1b'])->create()->ulid]))
        ->assertSessionHasErrors('community');
    // Unknown fields (e.g. a name) are simply not stored: there is no column.
    $this->post("/lgas/{$lga->ulid}/vulnerable-population", figuresPayload(['notes' => 'Musa, 34, blind']))->assertSessionHasNoErrors();
});

test('the latest figure per category is shown, with history and totals', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $factory = VulnerablePopulationStat::factory()->forLga($lga);
    $factory->create(['category' => VulnerableCategory::InternallyDisplaced, 'as_of_date' => '2026-01-01', 'adult_male' => 100, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0]);
    $latestIdp = $factory->create(['category' => VulnerableCategory::InternallyDisplaced, 'as_of_date' => '2026-06-01', 'adult_male' => 40, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0]);
    $elderly = $factory->create(['category' => VulnerableCategory::ElderlyWithoutSupport, 'as_of_date' => '2026-03-01', 'adult_male' => 5, 'adult_female' => 6, 'child_male' => 0, 'child_female' => 0]);
    // A community figure, newer: a breakdown, not the LGA-wide figure.
    VulnerablePopulationStat::factory()->forCommunity($community)->create(['category' => VulnerableCategory::InternallyDisplaced, 'as_of_date' => '2026-07-01']);
    // A deleted newer LGA-wide figure does not count.
    $factory->create(['category' => VulnerableCategory::InternallyDisplaced, 'as_of_date' => '2026-08-01'])->delete();

    $this->actingAs($world->users['lga_chief'])->get("/lgas/{$lga->ulid}?tab=vulnerable_population&history=internally_displaced")->assertInertia(fn (Assert $page) => $page
        ->where('vulnerablePopulation.latest', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$elderly->ulid, $latestIdp->ulid])
        ->where('vulnerablePopulation.totals.total', 51)
        ->has('vulnerablePopulation.byCommunity', 1)
        ->where('vulnerablePopulation.history', fn ($rows) => collect($rows)->pluck('as_of_date')->all() === ['2026-06-01', '2026-01-01']));

    // The community page shows the community's own figures.
    $this->get("/communities/{$community->ulid}?tab=vulnerable_population")->assertInertia(fn (Assert $page) => $page
        ->has('vulnerablePopulation.latest', 1)
        ->where('vulnerablePopulation.latest.0.as_of_date', '2026-07-01'));

    app(JurisdictionContext::class)->system();
    expect(TabData::totals([$latestIdp, $elderly]))->toBe(['adult_male' => 45, 'adult_female' => 6, 'child_male' => 0, 'child_female' => 0, 'total' => 51]);
});

test('only State Directors and above delete figures, with a logged reason', function () {
    $world = JurisdictionWorld::build();
    $figure = VulnerablePopulationStat::factory()->forLga($world->lgas['A1a'])->create();
    $url = "/vulnerable-population/{$figure->ulid}";

    $this->actingAs($world->users['lga_chief'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['director_general'])->delete($url, ['reason' => 'Wrong LGA'])->assertRedirect();

    app(JurisdictionContext::class)->system();
    expect(VulnerablePopulationStat::query()->find($figure->id))->toBeNull();
});
