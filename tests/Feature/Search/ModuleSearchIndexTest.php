<?php

use App\Models\Community;
use App\Models\CriminalGroup;
use App\Support\Search\Trigram;
use Illuminate\Support\Facades\DB;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Database\Statistics;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8A.6: the community and criminal group module searches use their
| trigram indexes (`<%` with the threshold set per transaction), not bare
| word_similarity() filters.
*/

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $lga = $this->world->lgas['A1a'];
    foreach (range(1, 40) as $i) {
        Community::factory()->in($lga)->create(['name' => "Settlement {$i}"]);
        CriminalGroup::factory()->in($lga)->create(['name' => "Group {$i}"]);
    }
    Community::factory()->in($lga)->create(['name' => 'Ajegunle']);
    CriminalGroup::factory()->in($lga)->create(['name' => 'Black Axe Confraternity']);
    Statistics::analyze('communities', 'criminal_groups');
});

/** Filter and index-condition lines of a plan (ranking by word_similarity() in the sort is fine). */
function conditions(string $plan): string
{
    return collect(explode("\n", $plan))->filter(fn (string $line) => str_contains($line, 'Filter:') || str_contains($line, 'Cond:'))->implode("\n");
}

/** The plan of $query with sequential scans off, inside the search threshold. */
function searchPlan(float $threshold, Closure $query): string
{
    return Trigram::withWordSimilarity($threshold, function () use ($query) {
        DB::statement('SET LOCAL enable_seqscan = off');

        return collect(DB::select('EXPLAIN '.$query()->toRawSql()))->pluck('QUERY PLAN')->implode("\n");
    });
}

test('the community search uses the trigram index and still tolerates typos', function () {
    $plan = searchPlan(Community::SEARCH_SIMILARITY, fn () => Community::query()->withoutGlobalScopes()->search('Ajegnle'));

    expect($plan)->toContain('communities_search_names_trgm')
        ->and(conditions($plan))->toContain('<%')->not->toContain('word_similarity(')
        ->and(Trigram::withWordSimilarity(Community::SEARCH_SIMILARITY, fn () => Community::query()->withoutGlobalScopes()->search('Ajegnle')->pluck('name')->first()))->toBe('Ajegunle');
});

test('the criminal group search uses the trigram index and still tolerates typos', function () {
    $plan = searchPlan(CriminalGroup::SEARCH_SIMILARITY, fn () => CriminalGroup::query()->withoutGlobalScopes()->search('Blak Axe'));

    expect($plan)->toContain('criminal_groups_search_name_trgm')
        ->and(conditions($plan))->toContain('<%')->not->toContain('word_similarity(')
        ->and(Trigram::withWordSimilarity(CriminalGroup::SEARCH_SIMILARITY, fn () => CriminalGroup::query()->withoutGlobalScopes()->search('Blak Axe')->pluck('name')->first()))->toBe('Black Axe Confraternity');
});
