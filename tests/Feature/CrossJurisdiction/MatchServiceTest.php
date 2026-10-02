<?php

use App\Actions\AccessRequests\ManageDelegations;
use App\Enums\Classification;
use App\Enums\MatchContext;
use App\Enums\MatchType;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\RecordMatchEvent;
use App\Notifications\MatchDigest;
use App\Notifications\MatchSoftLimitExceeded;
use App\Notifications\SuppressedMatchAlert;
use App\Support\CrossJurisdiction\HitToken;
use App\Support\CrossJurisdiction\InvalidHitToken;
use App\Support\CrossJurisdiction\MatchCriteria;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\PersonSearch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Database\Statistics;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(fn () => Notification::fake());

/*
|--------------------------------------------------------------------------
| Match types
|--------------------------------------------------------------------------
*/

test('each match type: exact by NIN or phone, strong needs name and DOB, possible never a hit', function () {
    $world = JurisdictionWorld::build();
    $b1 = $world->lgas['B1'];
    $exactNin = CrossFixtures::person($b1);
    $exactPhone = CrossFixtures::person($b1, ['nin' => null, 'phone' => '+2348035550100', 'first_name' => 'Other', 'last_name' => 'Phone', 'other_names' => null]);
    $strong = CrossFixtures::person($b1, ['nin' => null, 'first_name' => 'Ifeanyi', 'last_name' => 'Okoro', 'other_names' => null, 'date_of_birth' => '1980-05-05']);
    $possible = CrossFixtures::person($b1, ['nin' => null, 'first_name' => 'Chinedu', 'last_name' => 'Eze', 'other_names' => null, 'date_of_birth' => '1990-01-01']);
    $chief = $world->users['lga_chief'];

    $exact = CrossFixtures::byNin($chief);
    expect($exact->hits)->toHaveCount(1)->and($exact->hits[0]->strength)->toBe(MatchType::Exact);

    // Phone written differently (normalised) is exact.
    $phone = CrossFixtures::match($chief, MatchCriteria::fromPersonInput(['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0803 555 0100']));
    expect($phone->hits)->toHaveCount(1)->and($phone->hits[0]->strength)->toBe(MatchType::Exact);

    // Name + same DOB: strong. A misspelling still reaches 0.75.
    $likely = CrossFixtures::match($chief, new MatchCriteria(name: 'Ifeanyi Okoro', dateOfBirth: '1980-05-05'));
    expect($likely->hits)->toHaveCount(1)->and($likely->hits[0]->strength)->toBe(MatchType::Strong);

    // Same name, other DOB, or no DOB: possible, never shown.
    expect(CrossFixtures::match($chief, new MatchCriteria(name: 'Ifeanyi Okoro', dateOfBirth: '1981-01-01'))->hits)->toBe([])
        ->and(CrossFixtures::match($chief, new MatchCriteria(name: 'Chinedu Eze'))->hits)->toBe([])
        // Weak similarity is no match at all.
        ->and(CrossFixtures::match($chief, new MatchCriteria(name: 'Babatunde Fashola', dateOfBirth: '1990-01-01'))->hits)->toBe([]);

    // Only hits shown are recorded (no events for possible matches).
    CrossFixtures::system();
    expect(RecordMatchEvent::query()->count())->toBe(3);
});

test('aliases match like names', function () {
    $world = JurisdictionWorld::build();
    $person = CrossFixtures::person($world->lgas['B1'], ['nin' => null, 'first_name' => 'Real', 'last_name' => 'Name', 'other_names' => null, 'date_of_birth' => '1975-03-03']);
    $person->aliases()->create(['alias' => 'Commander Tiger']);

    $byAlias = CrossFixtures::match($world->users['lga_chief'], new MatchCriteria(name: 'Commander Tiger', dateOfBirth: '1975-03-03'));
    $criteriaAlias = CrossFixtures::match($world->users['lga_chief'], new MatchCriteria(name: 'Nobody Known', aliases: ['Real Name'], dateOfBirth: '1975-03-03'));

    expect($byAlias->hits)->toHaveCount(1)->and($byAlias->hits[0]->strength)->toBe(MatchType::Strong)
        ->and($criteriaAlias->hits)->toHaveCount(1);
});

test('thresholds are configurable', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'], ['nin' => null, 'first_name' => 'Ifeanyi', 'last_name' => 'Okoro', 'other_names' => null, 'date_of_birth' => '1980-05-05']);
    $criteria = new MatchCriteria(name: 'Ifeanyi Okoru', dateOfBirth: '1980-05-05');

    config()->set('buffalo.cross_jurisdiction.strong_similarity', 0.99);
    expect(CrossFixtures::match($world->users['lga_chief'], $criteria)->hits)->toBe([]);

    config()->set('buffalo.cross_jurisdiction.strong_similarity', 0.5);
    expect(CrossFixtures::match($world->users['lga_chief'], $criteria)->hits)->toHaveCount(1);
});

test('the name query uses the trigram index', function () {
    $world = JurisdictionWorld::build();
    DB::statement(<<<'SQL'
        INSERT INTO persons (ulid, state_id, lga_id, first_name, last_name, gender, classification, created_at, updated_at)
        SELECT lpad(to_hex(g), 26, '0'), ?, ?, 'First' || g, 'Last' || md5(g::text), 'unknown', 'restricted', now(), now()
        FROM generate_series(1, 50000) g
    SQL, [$world->lgas['B1']->state_id, $world->lgas['B1']->id]);
    Statistics::analyze('persons');

    $plan = app(JurisdictionContext::class)->runAsSystem('test', fn () => PersonSearch::withThresholds(0.6, 1.0, fn () => collect(DB::select('EXPLAIN '.
        PersonSearch::whereNameMatches(Person::query(), 'Garba Danjuma Musa', partial: false)->toRawSql()))
        ->map(fn ($r) => implode(' ', (array) $r))->implode("\n")));

    expect($plan)->toContain('Bitmap Index Scan on persons_search_name_trgm');
});

/*
|--------------------------------------------------------------------------
| Visibility, national users, classification
|--------------------------------------------------------------------------
*/

test('records the user can see are visible, never hits', function () {
    $world = JurisdictionWorld::build();
    $own = CrossFixtures::person($world->lgas['A1a']);

    $result = CrossFixtures::byNin($world->users['lga_chief']);

    expect($result->hits)->toBe([])
        ->and($result->visible->pluck('id')->all())->toBe([$own->id]);
});

test('national users never get hits', function (string $who) {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1']);

    expect(CrossFixtures::byNin($world->users[$who])->hits)->toBe([]);
})->with(['director_general', 'ddg_national', 'ddg_zone_a']);

test('confidential above clearance: no hit for an LGA chief, the owning State Director told once a day', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'], ['classification' => Classification::Confidential]);
    $owner = CrossFixtures::directorOfB1($world);
    $chief = $world->users['lga_chief'];

    expect(CrossFixtures::byNin($chief)->hits)->toBe([])
        ->and(CrossFixtures::byNin($chief)->hits)->toBe([]);

    Notification::assertSentToTimes($owner, SuppressedMatchAlert::class, 1);
    Notification::assertSentTo($owner, SuppressedMatchAlert::class, fn (SuppressedMatchAlert $n) => str_contains($n->body($owner), $chief->name) && ! str_contains($n->body($owner), 'Garba'));
    Notification::assertNotSentTo($world->users['director_general'], SuppressedMatchAlert::class);

    // Both suppressed matches are recorded.
    CrossFixtures::system();
    expect(RecordMatchEvent::query()->where('suppressed', true)->count())->toBe(2)
        ->and(RecordMatchEvent::query()->where('suppressed', false)->count())->toBe(0);

    // A new day: told again.
    $this->travel(1)->days();
    CrossFixtures::byNin($chief);
    Notification::assertSentToTimes($owner, SuppressedMatchAlert::class, 2);
});

test('secret: a State Director elsewhere gets a hit; an LGA chief gets none and the DG and DDGs are told', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'], ['classification' => Classification::Secret]);

    expect(CrossFixtures::byNin($world->users['state_director'])->hits)->toHaveCount(1)
        ->and(CrossFixtures::byNin($world->users['lga_chief'])->hits)->toBe([]);

    Notification::assertSentTo($world->users['director_general'], SuppressedMatchAlert::class);
    Notification::assertSentTo($world->users['ddg_national'], SuppressedMatchAlert::class);
    Notification::assertSentTo($world->users['ddg_zone_a'], SuppressedMatchAlert::class);
});

test('a confidential record gives a Deputy State Director elsewhere a hit (clearance allows)', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'], ['classification' => Classification::Confidential]);

    expect(CrossFixtures::byNin($world->users['deputy_state_director'])->hits)->toHaveCount(1);
});

test('soft-deleted persons are never matched', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'])->delete();

    expect(CrossFixtures::byNin($world->users['lga_chief'])->hits)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Hit payloads and tokens
|--------------------------------------------------------------------------
*/

test('hits reveal only the owning state, category and strength, in the props too', function () {
    $world = JurisdictionWorld::build();
    $person = CrossFixtures::person($world->lgas['B1']);
    $person->aliases()->create(['alias' => 'Alhaji Tiger']);
    PersonOfInterest::factory()->forPerson($person)->create(['known_offences' => 'Kidnapping on the Kaduna road']);

    $props = $this->actingAs($world->users['lga_chief'])->post('/persons/search', ['q' => CrossFixtures::NIN])->inertiaProps('crossJurisdiction');

    expect($props['hits'])->toHaveCount(1)
        ->and(array_keys($props['hits'][0]))->toBe(['token', 'state', 'category', 'strength'])
        ->and($props['hits'][0]['state'])->toBe($world->states['B1']->name)
        ->and($props['hits'][0]['category'])->toBe('Person of interest')
        ->and($props['hits'][0]['strength'])->toBe('Record found');

    CrossFixtures::system();
    $forbidden = ['Garba', 'Musa', 'Danjuma', 'Alhaji Tiger', 'Kidnapping', $person->ulid, $world->lgas['B1']->name, $world->lgas['B1']->ulid, (string) $person->id, CrossFixtures::NIN];
    $strings = collect(CrossFixtures::strings($props['hits']))->reject(fn ($s) => $s === $props['hits'][0]['token']);

    foreach ($forbidden as $secret) {
        expect($strings->contains(fn (string $s) => str_contains($s, $secret)))->toBeFalse("hit leaks {$secret}");
    }

    // The token is encrypted: nothing readable in it.
    foreach ($forbidden as $secret) {
        expect(str_contains($props['hits'][0]['token'], $secret))->toBeFalse();
    }
});

test('national users and non-requesters get no cross-jurisdiction panel', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1']);

    $this->actingAs($world->users['director_general'])->post('/persons/search', ['q' => CrossFixtures::NIN])
        ->assertInertia(fn ($page) => $page->where('crossJurisdiction', null));
});

test('hit tokens: expired, tampered or foreign tokens are rejected', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1']);
    $chief = $world->users['lga_chief'];
    $token = CrossFixtures::byNin($chief)->hits[0]->token;

    expect(HitToken::decode($token, $chief)['match_type'])->toBe(MatchType::Exact);

    expect(fn () => HitToken::decode($token, $world->users['state_director']))->toThrow(InvalidHitToken::class)
        ->and(fn () => HitToken::decode(substr($token, 0, -4).'AAAA', $chief))->toThrow(InvalidHitToken::class)
        ->and(fn () => HitToken::decode(Crypt::encryptString('{"p":1}'), $chief))->toThrow(InvalidHitToken::class);

    $this->travel(121)->minutes();
    expect(fn () => HitToken::decode($token, $chief))->toThrow(InvalidHitToken::class);
});

/*
|--------------------------------------------------------------------------
| Match events, limits, digest
|--------------------------------------------------------------------------
*/

test('match events record who matched what, and are immutable', function () {
    $world = JurisdictionWorld::build();
    $person = CrossFixtures::person($world->lgas['B1']);

    CrossFixtures::match($world->users['lga_chief'], new MatchCriteria(nin: CrossFixtures::NIN), MatchContext::PersonCreate);

    CrossFixtures::system();
    $event = RecordMatchEvent::query()->sole();

    expect($event->only(['user_id', 'subject_id', 'owning_state_id', 'owning_lga_id', 'suppressed']))->toBe([
        'user_id' => $world->users['lga_chief']->id,
        'subject_id' => $person->id,
        'owning_state_id' => $person->state_id,
        'owning_lga_id' => $person->lga_id,
        'suppressed' => false,
    ])->and($event->context)->toBe(MatchContext::PersonCreate);

    expect(fn () => DB::table('record_match_events')->update(['suppressed' => true]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('record_match_events')->delete())->toThrow(QueryException::class);
});

test('the soft limit notifies once a day; the hard limit stops hits and says so', function () {
    config()->set('buffalo.cross_jurisdiction.soft_daily_limit', 2);
    config()->set('buffalo.cross_jurisdiction.hard_daily_limit', 4);
    $world = JurisdictionWorld::build();
    $chief = $world->users['lga_chief'];

    foreach (range(1, 5) as $n) {
        CrossFixtures::person($world->lgas['B1'], ['nin' => '5234567890'.$n]);
    }

    foreach (range(1, 4) as $n) {
        expect(CrossFixtures::byNin($chief, '5234567890'.$n)->hits)->toHaveCount(1);
    }

    Notification::assertSentToTimes($world->users['director_general'], MatchSoftLimitExceeded::class, 1);
    Notification::assertSentTo($world->users['ddg_zone_a'], MatchSoftLimitExceeded::class);
    Notification::assertNotSentTo($world->users['ddg_zone_b'], MatchSoftLimitExceeded::class);

    $blocked = CrossFixtures::byNin($chief, '52345678905');
    expect($blocked->hits)->toBe([])->and($blocked->limitReached)->toBeTrue();

    $this->actingAs($chief)->post('/persons/search', ['q' => '52345678905'])
        ->assertInertia(fn ($page) => $page->where('crossJurisdiction.limitReached', true)->where('crossJurisdiction.hits', []));

    CrossFixtures::system();
    expect(Activity::query()->where('event', 'match_hard_limit_reached')->count())->toBe(1);
});

test('the owner digest goes to the State Director and delegated deputy, by requesting state and officer', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1']);
    $owner = CrossFixtures::directorOfB1($world);
    $deputy = CrossFixtures::deputyOfB1($world);
    $otherDeputy = CrossFixtures::deputyOfB1($world);
    app(ManageDelegations::class)->create($owner, $deputy, now()->subHour()->toImmutable(), null, null);

    CrossFixtures::byNin($world->users['lga_chief']);
    CrossFixtures::byNin($world->users['state_director']);

    $this->artisan('buffalo:match-digest')->assertSuccessful();

    Notification::assertSentTo($owner, MatchDigest::class, fn (MatchDigest $n) => $n->total === 2
        && str_contains($n->body($owner), $world->states['A1']->name.' State: 2')
        && str_contains($n->body($owner), $world->users['lga_chief']->name)
        && ! str_contains($n->body($owner), 'Garba'));
    Notification::assertSentTo($deputy, MatchDigest::class);
    Notification::assertNotSentTo($otherDeputy, MatchDigest::class);
    Notification::assertNotSentTo($world->users['state_director'], MatchDigest::class);
});

/*
|--------------------------------------------------------------------------
| Architecture
|--------------------------------------------------------------------------
*/

test('only PersonMatchService queries persons across jurisdictions outside App\Support\Jurisdiction', function () {
    $allowedDir = (string) realpath(base_path('app/Support/Jurisdiction'));
    $allowedFile = (string) realpath(base_path('app/Support/CrossJurisdiction/PersonMatchService.php'));
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            $path = (string) $file->getRealPath();

            if ($file->getExtension() !== 'php' || str_starts_with($path, $allowedDir) || $path === $allowedFile) {
                continue;
            }

            if (preg_match('/runAsSystem\s*\(/', $file->getContents()) === 1) {
                $violations[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($violations)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Date of birth in the search box (Phase 4A.0)
|--------------------------------------------------------------------------
*/

test('a name with a matching date of birth in the search box gives a likely match; a name alone does not', function () {
    $world = JurisdictionWorld::build();
    CrossFixtures::person($world->lgas['B1'], ['nin' => null, 'first_name' => 'Ifeanyi', 'last_name' => 'Okoro', 'other_names' => null, 'date_of_birth' => '1980-05-05']);
    $chief = $world->users['lga_chief'];

    CrossFixtures::signIn($chief)->post('/persons/search', ['q' => 'Ifeanyi Okoro', 'dob' => '1980-05-05'])
        ->assertInertia(fn ($page) => $page->has('crossJurisdiction.hits', 1)
            ->where('crossJurisdiction.hits.0.strength', 'Likely match')
            ->where('filters.dob', '1980-05-05'));

    CrossFixtures::signIn($chief)->post('/persons/search', ['q' => 'Ifeanyi Okoro'])
        ->assertInertia(fn ($page) => $page->has('crossJurisdiction.hits', 0));

    CrossFixtures::signIn($chief)->post('/persons/search', ['q' => 'Ifeanyi Okoro', 'dob' => '1981-01-01'])
        ->assertInertia(fn ($page) => $page->has('crossJurisdiction.hits', 0));

    CrossFixtures::signIn($chief)->post('/persons/search', ['q' => 'Ifeanyi Okoro', 'dob' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors('dob');
});
