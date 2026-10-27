<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\PoiStatus;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\RecordAccessLog;
use App\Models\RecordDissemination;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use App\Services\Search\Providers\IncidentProvider;
use App\Services\Search\SearchBudget;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchQuery;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| The world: state A1 (LGAs A1a, A1b), A2, B1. The world's SD, DSD and
| chief belong to A1 / A1a. Records named "Zarmaq …" exist in A1a (inside)
| and B1 (outside).
*/
beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chief = $this->world->users['lga_chief'];
    $this->inside = $this->world->lgas['A1a'];
    $this->outside = $this->world->lgas['B1'];
});

function quickSearch(User $user, string $q): array
{
    return CrossFixtures::signIn($user)->getJson('/search/quick?q='.urlencode($q))->assertOk()->json();
}

/** @return list<string> titles in one section */
function sectionTitles(array $results, string $key): array
{
    return collect($results['sections'])->firstWhere('key', $key)['hits'] ?? null
        ? array_column(collect($results['sections'])->firstWhere('key', $key)['hits'], 'title')
        : [];
}

function seedZarmaq(Lga $lga, string $suffix, User $creator, array $incident = []): void
{
    CrossFixtures::system();
    W::at(W::draft($lga, $creator, ['title' => "Zarmaq raid {$suffix}", ...$incident]), IncidentStatus::Verified);
    T::threat($lga, ['title' => "Zarmaq threat {$suffix}"]);
    F::flashpoint($lga, ['name' => "Zarmaq corridor {$suffix}"]);
    $person = Person::factory()->in($lga)->named('Zarmaq', "Person{$suffix}")->create();
    PersonOfInterest::factory()->forPerson(Person::factory()->in($lga)->named('Zarmaq', "Suspect{$suffix}")->create())->create();
    CriminalGroup::factory()->in($lga)->create(['name' => "Zarmaq Boys {$suffix}"]);
    Community::factory()->in($lga)->create(['name' => "Zarmaq village {$suffix}"]);
    OrganizationSite::factory()->in($lga)->of(Organization::factory()->create(['name' => "Directory {$suffix} Ltd"]))->create(['name' => "Zarmaq depot {$suffix}"]);
    AgencyFormation::factory()->in($lga)->create(['name' => "Zarmaq barracks {$suffix}"]);
    DB::table('lgas')->where('id', $lga->id)->update(['name' => "Zarmaq {$suffix}"]);
    expect($person)->not->toBeNull();
}

/*
|--------------------------------------------------------------------------
| Scopes
|--------------------------------------------------------------------------
*/

test('every provider returns only in-scope records, each in its own section', function () {
    seedZarmaq($this->inside, 'Inside', $this->chief);
    seedZarmaq($this->outside, 'Outside', CrossFixtures::chiefOf($this->outside));

    $results = quickSearch($this->sd, 'Zarmaq');

    foreach ([
        'incidents' => 'Zarmaq raid Inside',
        'threats' => 'Zarmaq threat Inside',
        'flashpoints' => 'Zarmaq corridor Inside',
        'persons' => 'Zarmaq PersonInside',
        'pois' => 'Zarmaq SuspectInside',
        'groups' => 'Zarmaq Boys Inside',
        'communities' => 'Zarmaq village Inside',
        'organizations' => 'Zarmaq depot Inside',
        'formations' => 'Zarmaq barracks Inside',
        'places' => 'Zarmaq Inside LGA',
    ] as $section => $title) {
        $titles = sectionTitles($results, $section);
        expect($titles)->toContain($title)
            ->and(collect($titles)->filter(fn ($t) => str_contains($t, 'Outside'))->all())->toBe([], "{$section} leaks out-of-jurisdiction records");
    }

    // The national user sees both.
    $national = quickSearch($this->world->users['director_general'], 'Zarmaq raid');
    expect(sectionTitles($national, 'incidents'))->toContain('Zarmaq raid Inside', 'Zarmaq raid Outside');
});

test('the classification ceiling applies: a deputy never sees a secret incident or threat', function () {
    $author = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    W::at(W::draft($this->inside, $author, ['title' => 'Zarmaq covert raid', 'classification' => Classification::Secret]), IncidentStatus::Verified);
    T::threat($this->inside, ['title' => 'Zarmaq covert threat'], Classification::Secret);

    expect(sectionTitles(quickSearch($this->dsd, 'Zarmaq covert'), 'incidents'))->toBe([])
        ->and(sectionTitles(quickSearch($this->dsd, 'Zarmaq covert'), 'threats'))->toBe([])
        ->and(sectionTitles(quickSearch($this->sd, 'Zarmaq covert'), 'incidents'))->toBe(['Zarmaq covert raid'])
        ->and(sectionTitles(quickSearch($this->sd, 'Zarmaq covert'), 'threats'))->toBe(['Zarmaq covert threat']);

    CrossFixtures::signIn($this->dsd)->get('/search?q=Zarmaq+covert')->assertInertia(fn (Assert $page) => $page
        ->where('results.tabs', fn ($tabs) => collect($tabs)->every(fn ($t) => $t['count'] === 0)));
});

test('disseminated threats and flashpoints are found by recipients only while shared', function () {
    $threat = T::threat($this->outside, ['title' => 'Zarmaq shared warning']);
    expect(sectionTitles(quickSearch($this->sd, 'Zarmaq shared'), 'threats'))->toBe([]);

    $owner = CrossFixtures::directorOfB1($this->world);
    CrossFixtures::signIn($owner)->post("/threats/{$threat->ulid}/disseminations", [
        'targets' => [['level' => 'state', 'ulid' => $this->world->states['A1']->ulid]],
        'reason' => 'The gang is expected to move into A1 this week.',
    ])->assertSessionHasNoErrors();

    expect(sectionTitles(quickSearch($this->sd, 'Zarmaq shared'), 'threats'))->toBe(['Zarmaq shared warning'])
        ->and(sectionTitles(quickSearch($this->chief, 'Zarmaq shared'), 'threats'))->toBe([]);

    RecordDissemination::query()->sole()->forceFill(['revoked_at' => now(), 'revoked_by' => $owner->id])->save();
    expect(sectionTitles(quickSearch($this->sd, 'Zarmaq shared'), 'threats'))->toBe([]);
});

test('persons: grants are respected, and global search never produces cross-jurisdiction hits', function () {
    CrossFixtures::person($this->outside);
    $events = fn () => DB::table('record_match_events')->count();
    $before = $events();

    $byNin = quickSearch($this->chief, CrossFixtures::NIN);
    expect($byNin['type'])->toBe('nin')
        ->and(collect($byNin['sections'])->pluck('key')->all())->not->toContain('persons')
        ->and($events())->toBe($before);
    expect(sectionTitles(quickSearch($this->chief, 'Garba Musa'), 'persons'))->toBe([])
        ->and($events())->toBe($before);

    // With an approved grant the person is found, exactly and first.
    CrossFixtures::grant($this->chief, CrossFixtures::directorOfB1($this->world));
    $before = $events();
    $granted = quickSearch($this->chief, CrossFixtures::NIN);
    expect($granted['top'][0]['title'])->toBe('Garba Danjuma Musa')
        ->and($granted['top'][0]['exact'])->toBeTrue()
        ->and($events())->toBe($before);
});

test('super admin gets nothing', function () {
    seedZarmaq($this->inside, 'Inside', $this->chief);
    $admin = $this->world->users['super_admin'];

    CrossFixtures::signIn($admin)->getJson('/search/quick?q=Zarmaq')->assertForbidden();
    CrossFixtures::signIn($admin)->get('/search?q=Zarmaq')->assertForbidden();
    expect(app(GlobalSearch::class)->quick($admin, 'Zarmaq')['sections'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Query detection and ranking
|--------------------------------------------------------------------------
*/

test('query detection: references, NIN, phone and text', function () {
    expect(SearchQuery::parse('thr/la/2026/00001')->type)->toBe('reference')
        ->and(SearchQuery::parse('thr/la/2026/00001')->reference)->toBe('THR/LA/2026/00001')
        ->and(SearchQuery::parse('BUF/LA/IKJ/2026/000001')->referencePrefix())->toBe('BUF')
        ->and(SearchQuery::parse('12345678901')->type)->toBe('nin')
        ->and(SearchQuery::parse('08031234567')->phone)->toBe('+2348031234567')
        ->and(SearchQuery::parse('+234 803 123 4567')->type)->toBe('phone')
        ->and(SearchQuery::parse('Ikeja market')->type)->toBe('text')
        ->and(SearchQuery::parse('a')->isSearchable())->toBeFalse()
        ->and(SearchQuery::parse('ab')->isSearchable())->toBeTrue();

    expect(quickSearch($this->sd, 'a')['sections'])->toBe([]);
});

test('a reference is an exact lookup only and opens the record first', function () {
    $threat = T::threat($this->inside, ['title' => 'Zarmaq ambush']);
    $incident = W::at(W::draft($this->inside, $this->chief, ['title' => 'Zarmaq ambush report']), IncidentStatus::Verified);

    $byThreat = quickSearch($this->sd, strtolower($threat->reference_no));
    expect($byThreat['type'])->toBe('reference')
        ->and(collect($byThreat['sections'])->pluck('key')->all())->toBe(['threats'])
        ->and($byThreat['top'][0])->toMatchArray(['ulid' => $threat->ulid, 'exact' => true, 'href' => "/threats/{$threat->ulid}"]);

    $byIncident = quickSearch($this->sd, $incident->reference_no);
    expect(collect($byIncident['sections'])->pluck('key')->all())->toBe(['incidents'])
        ->and($byIncident['top'][0]['ulid'])->toBe($incident->ulid);

    // Out of scope: nothing.
    $other = T::threat($this->outside);
    expect(quickSearch($this->sd, $other->reference_no)['top'])->toBe([]);
});

test('ranking: exact matches first, then score, then recency', function () {
    $hit = fn (string $id, float $score, bool $exact = false, ?string $at = null) => new SearchHit('x', $id, $id, '', '/', null, $score, $exact, $at === null ? null : now()->parse($at));
    $ranked = SearchHit::rank([
        $hit('low', 0.2), $hit('exact', 0.1, true), $hit('high-old', 0.9, false, '2026-01-01'), $hit('high-new', 0.9, false, '2026-09-01'),
    ]);

    expect(array_map(fn (SearchHit $h) => $h->ulid, $ranked))->toBe(['exact', 'high-new', 'high-old', 'low']);
});

/*
|--------------------------------------------------------------------------
| Budget, logs, limits
|--------------------------------------------------------------------------
*/

test('a provider that runs out of time is reported, not fatal', function () {
    config(['buffalo.search.provider_timeout_ms' => 100]);
    W::at(W::draft($this->inside, $this->chief, ['title' => 'Zarmaq timely raid']), IncidentStatus::Verified);
    $slow = new class implements SearchProvider
    {
        public function key(): string
        {
            return 'slow';
        }

        public function label(): string
        {
            return 'Slow';
        }

        public function allows(User $user): bool
        {
            return true;
        }

        public function supports(SearchQuery $query): bool
        {
            return true;
        }

        public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
        {
            DB::select('SELECT pg_sleep(1)');

            return [];
        }

        public function count(SearchQuery $query, User $user): int
        {
            DB::select('SELECT pg_sleep(1)');

            return 0;
        }
    };
    $search = new GlobalSearch([$slow, app(IncidentProvider::class)]);

    $quick = app(JurisdictionContext::class)->actingAs($this->sd, fn () => $search->quick($this->sd, 'Zarmaq timely'));
    expect(collect($quick['sections'])->firstWhere('key', 'slow'))->toMatchArray(['timedOut' => true, 'more' => true, 'hits' => []])
        ->and(sectionTitles($quick, 'incidents'))->toBe(['Zarmaq timely raid']);

    $full = app(JurisdictionContext::class)->actingAs($this->sd, fn () => $search->full($this->sd, 'Zarmaq timely', 'incidents', 1));
    expect(collect($full['tabs'])->firstWhere('key', 'slow'))->toMatchArray(['count' => null, 'timedOut' => true])
        ->and(collect($full['tabs'])->firstWhere('key', 'incidents')['count'])->toBe(1);

    // The enclosing transaction keeps its normal timeout afterwards.
    expect(DB::selectOne("SELECT current_setting('statement_timeout') AS t")->t)->not->toBe('100ms');
    expect(SearchBudget::run(fn () => DB::selectOne('SELECT 1 AS one')->one))->toBe(1);
});

test('search_logs stores a hash, never the query, and is append-only', function () {
    quickSearch($this->sd, 'Zarmaq Bello');
    CrossFixtures::signIn($this->sd)->get('/search?q='.CrossFixtures::NIN)->assertOk();

    $rows = DB::table('search_logs')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->query_hash)->toBe(hash('sha256', 'zarmaq bello'))
        ->and($rows[0]->query_type)->toBe('text')
        ->and($rows[1]->query_type)->toBe('nin')
        ->and($rows[0]->user_id)->toBe($this->sd->id);
    expect(json_encode($rows))->not->toContain('Zarmaq')->not->toContain(CrossFixtures::NIN);

    expect(fn () => DB::table('search_logs')->update(['result_count' => 99]))->toThrow(Exception::class);
    expect(fn () => DB::table('search_logs')->delete())->toThrow(Exception::class);
});

test('searches are rate limited per user with a friendly message', function () {
    config(['buffalo.search.per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        CrossFixtures::signIn($this->sd)->getJson('/search/quick?q=Zarmaq')->assertOk();
    }

    CrossFixtures::signIn($this->sd)->getJson('/search/quick?q=Zarmaq')
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many searches. Wait a minute and try again.']);
    // Another user is unaffected.
    CrossFixtures::signIn($this->dsd)->getJson('/search/quick?q=Zarmaq')->assertOk();
});

test('listing results writes no record_access_logs; opening a record still does', function () {
    $threat = T::threat($this->inside, ['title' => 'Zarmaq confidential warning'], Classification::Confidential);
    $person = Person::factory()->in($this->inside)->named('Zarmaq', 'Listed')->create();

    quickSearch($this->sd, 'Zarmaq');
    CrossFixtures::signIn($this->sd)->get('/search?q=Zarmaq&type=persons')->assertOk();
    expect(RecordAccessLog::query()->count())->toBe(0);

    CrossFixtures::signIn($this->sd)->get("/threats/{$threat->ulid}")->assertOk();
    expect(RecordAccessLog::query()->where('subject_id', $threat->id)->count())->toBe(1);
    expect($person)->not->toBeNull();
});

test('tab counts are the viewer\'s own scoped counts, paginated', function () {
    config(['buffalo.search.page_size' => 2]);
    foreach (range(1, 3) as $i) {
        W::at(W::draft($this->inside, $this->chief, ['title' => "Zarmaq counted raid {$i}"]), IncidentStatus::Verified);
    }
    foreach (range(1, 2) as $i) {
        W::at(W::draft($this->outside, CrossFixtures::chiefOf($this->outside), ['title' => "Zarmaq counted raid elsewhere {$i}"]), IncidentStatus::Verified);
    }

    CrossFixtures::signIn($this->sd)->get('/search?q=Zarmaq+counted&type=incidents')->assertInertia(fn (Assert $page) => $page
        ->component('search/index')
        ->where('results.active', 'incidents')
        ->where('results.tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'incidents')['count'] === 3)
        ->has('results.hits', 2)
        ->where('results.pages', 2));
    CrossFixtures::signIn($this->sd)->get('/search?q=Zarmaq+counted&type=incidents&page=2')->assertInertia(fn (Assert $page) => $page->has('results.hits', 1));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/search?q=Zarmaq+counted&type=incidents')->assertInertia(fn (Assert $page) => $page
        ->where('results.tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'incidents')['count'] === 5));

    // At most max_results are listed (counts stay exact).
    config(['buffalo.search.max_results' => 2, 'buffalo.search.page_size' => 1]);
    CrossFixtures::signIn($this->sd)->get('/search?q=Zarmaq+counted&type=incidents')->assertInertia(fn (Assert $page) => $page
        ->where('results.pages', 2)
        ->where('results.limit', 2)
        ->where('results.tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'incidents')['count'] === 3));
});

test('active persons of interest only, with their risk level', function () {
    PersonOfInterest::factory()->forPerson(Person::factory()->in($this->inside)->named('Zarmaq', 'Cleared')->create())->create(['status' => PoiStatus::Cleared]);
    PersonOfInterest::factory()->forPerson(Person::factory()->in($this->inside)->named('Zarmaq', 'Wanted')->create())->create(['risk_level' => 'high']);

    $hits = collect(quickSearch($this->sd, 'Zarmaq')['sections'])->firstWhere('key', 'pois')['hits'];
    expect(array_column($hits, 'title'))->toBe(['Zarmaq Wanted'])
        ->and($hits[0]['badge'])->toBe('high')
        ->and($hits[0]['href'])->toEndWith('?tab=poi');
});

test('states are found by name or code within reach (Phase 11F)', function () {
    $this->world->states['A1']->forceFill(['name' => 'Qorvath Highlands', 'code' => 'QH'])->save();
    $this->world->states['B1']->forceFill(['name' => 'Qorvath Lowlands', 'code' => 'QL'])->save();

    expect(sectionTitles(quickSearch($this->sd, 'Qorvath'), 'places'))->toBe(['Qorvath Highlands State'])
        ->and(sectionTitles(quickSearch($this->chief, 'qorvath highlands'), 'places'))->toBe(['Qorvath Highlands State'])
        ->and(sectionTitles(quickSearch($this->world->users['director_general'], 'Qorvath'), 'places'))->toContain('Qorvath Highlands State', 'Qorvath Lowlands State')
        ->and(sectionTitles(quickSearch($this->world->users['director_general'], 'QL'), 'places'))->toContain('Qorvath Lowlands State')
        ->and(sectionTitles(quickSearch($this->sd, 'QL'), 'places'))->toBe([]);

    $hit = collect(quickSearch($this->sd, 'Qorvath')['sections'])->firstWhere('key', 'places')['hits'][0];
    expect($hit['href'])->toBe('/states/'.$this->world->states['A1']->ulid);
});
