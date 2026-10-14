<?php

use App\Enums\Classification;
use App\Models\Community;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\PersonSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Database\Statistics;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

/*
|--------------------------------------------------------------------------
| Scope and classification matrix
|--------------------------------------------------------------------------
*/

test('persons follow the jurisdiction matrix', function (string $who) {
    $world = JurisdictionWorld::build();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        Person::factory()->in($world->lgas[$lga])->named('Test', $label)->create();
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $label) => $label !== 'state_level'));
    sort($expected);

    $visible = app(JurisdictionContext::class)->actingAs(
        $world->users[$who]->fresh() ?? $world->users[$who],
        fn () => Person::query()->orderBy('last_name')->pluck('last_name')->all(),
    );

    expect($visible)->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('person classification composes with jurisdiction, with the creator exception', function () {
    $world = JurisdictionWorld::build();
    $deputy = $world->users['deputy_state_director'];

    foreach (['A1a', 'B1'] as $place) {
        foreach (Classification::cases() as $level) {
            Person::factory()->in($world->lgas[$place])->classified($level)->named($place, $level->value)->create();
        }
    }
    Person::factory()->in($world->lgas['A1b'])->classified(Classification::Secret)->named('A1b', 'own-secret')->create(['created_by' => $deputy->id]);

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs(
        $world->users[$who]->fresh() ?? $world->users[$who],
        fn () => Person::query()->orderBy('first_name')->orderBy('last_name')->get()->map(fn (Person $p) => "{$p->first_name}-{$p->last_name}")->all(),
    );

    expect($visible('lga_chief'))->toBe(['A1a-restricted'])
        ->and($visible('deputy_state_director'))->toBe(['A1a-confidential', 'A1a-restricted', 'A1b-own-secret'])
        ->and($visible('state_director'))->toBe(['A1a-confidential', 'A1a-restricted', 'A1a-secret', 'A1b-own-secret'])
        ->and($visible('director_general'))->toHaveCount(7)
        ->and($visible('super_admin'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Policy, per role
|--------------------------------------------------------------------------
*/

test('the person policy for each role, including another state or LGA', function (string $who, array $expected) {
    $world = JurisdictionWorld::build();
    $own = Person::factory()->in($world->lgas['A1a'])->create();
    $otherLga = Person::factory()->in($world->lgas['A1b'])->create();
    $otherState = Person::factory()->in($world->lgas['B1'])->create();
    $user = $world->users[$who];

    $actual = [
        'viewAny' => $user->can('viewAny', Person::class),
        'create' => $user->can('create', Person::class),
        'view_own' => $user->can('view', $own),
        'update_own' => $user->can('update', $own),
        'delete_own' => $user->can('delete', $own),
        'view_other_lga' => $user->can('view', $otherLga),
        'view_other_state' => $user->can('view', $otherState),
        'update_other_state' => $user->can('update', $otherState),
    ];

    expect($actual)->toBe($expected);
})->with([
    'super admin' => ['super_admin', ['viewAny' => false, 'create' => false, 'view_own' => false, 'update_own' => false, 'delete_own' => false, 'view_other_lga' => false, 'view_other_state' => false, 'update_other_state' => false]],
    'director general' => ['director_general', ['viewAny' => true, 'create' => true, 'view_own' => true, 'update_own' => true, 'delete_own' => true, 'view_other_lga' => true, 'view_other_state' => true, 'update_other_state' => true]],
    'zoned ddg (zone A)' => ['ddg_zone_a', ['viewAny' => true, 'create' => true, 'view_own' => true, 'update_own' => true, 'delete_own' => true, 'view_other_lga' => true, 'view_other_state' => false, 'update_other_state' => false]],
    'state director' => ['state_director', ['viewAny' => true, 'create' => true, 'view_own' => true, 'update_own' => true, 'delete_own' => true, 'view_other_lga' => true, 'view_other_state' => false, 'update_other_state' => false]],
    'deputy state director' => ['deputy_state_director', ['viewAny' => true, 'create' => true, 'view_own' => true, 'update_own' => true, 'delete_own' => false, 'view_other_lga' => true, 'view_other_state' => false, 'update_other_state' => false]],
    'lga chief' => ['lga_chief', ['viewAny' => true, 'create' => true, 'view_own' => true, 'update_own' => true, 'delete_own' => false, 'view_other_lga' => false, 'view_other_state' => false, 'update_other_state' => false]],
    'inactive dg' => ['inactive_dg', ['viewAny' => false, 'create' => false, 'view_own' => false, 'update_own' => false, 'delete_own' => false, 'view_other_lga' => false, 'view_other_state' => false, 'update_other_state' => false]],
    'no role' => ['no_role', ['viewAny' => false, 'create' => false, 'view_own' => false, 'update_own' => false, 'delete_own' => false, 'view_other_lga' => false, 'view_other_state' => false, 'update_other_state' => false]],
]);

test('persons pages are forbidden or 404 outside jurisdiction', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1b'])->create();

    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}")->assertNotFound();
    $this->actingAs($world->users['lga_chief'])->get("/persons/{$person->ulid}/edit")->assertNotFound();
    $this->actingAs($world->users['lga_chief'])->put("/persons/{$person->ulid}", [])->assertNotFound();
    $this->flushSession();
    $this->actingAs($world->users['super_admin'])->get('/persons')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Create, edit, roles
|--------------------------------------------------------------------------
*/

test('an LGA chief creates a person with roles; the audit shows fields, never protected values', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();

    $this->actingAs($world->users['lga_chief'])
        ->post('/persons', PersonFixtures::payload($lga, ['community' => $community->ulid]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    PersonFixtures::system();
    $person = Person::query()->sole();

    expect($person->lga_id)->toBe($lga->id)
        ->and($person->state_id)->toBe($lga->state_id)
        ->and($person->community_id)->toBe($community->id)
        ->and($person->phone)->toBe(PersonFixtures::SECRETS['phone_normalised'])
        ->and($person->nin)->toBe(PersonFixtures::SECRETS['nin'])
        ->and($person->created_by)->toBe($world->users['lga_chief']->id)
        ->and($person->roles->map(fn (PersonRole $r) => [$r->typeCode(), $r->is_active, $r->notes])->all())->toBe([['community_leader', true, 'Youth leader']]);

    $log = Activity::query()->get()->map(fn (Activity $a) => json_encode([$a->attribute_changes, $a->properties, $a->description]))->implode("\n");

    foreach ([PersonFixtures::SECRETS['nin'], PersonFixtures::SECRETS['phone_normalised'], '8035550199', PersonFixtures::SECRETS['date_of_birth'], PersonFixtures::SECRETS['address']] as $secret) {
        expect($log)->not->toContain($secret);
    }

    expect($log)->toContain('Babatunde')->toContain('Protected fields recorded');
});

test('the owning LGA must be within the user\'s jurisdiction, and the community in that LGA', function () {
    $world = JurisdictionWorld::build();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($world->lgas['A1b']))->assertSessionHasErrors('lga');

    $elsewhere = Community::factory()->in($world->lgas['A1b'])->create();
    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($world->lgas['A1a'], ['community' => $elsewhere->ulid]))->assertSessionHasErrors('community');

    PersonFixtures::system();
    expect(Person::query()->count())->toBe(0);
});

test('validation of NIN, phone, date of birth and age', function (array $overrides, string $field) {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['lga_chief'])
        ->post('/persons', PersonFixtures::payload($world->lgas['A1a'], $overrides))
        ->assertSessionHasErrors($field);
})->with([
    'NIN too short' => [['nin' => '1234567890'], 'nin'],
    'NIN with letters' => [['nin' => '1234567890A'], 'nin'],
    'foreign phone' => [['phone' => '+44 20 7946 0958'], 'phone'],
    'future birth date' => [['date_of_birth' => now()->addDay()->toDateString()], 'date_of_birth'],
    'age and birth date' => [['approximate_age' => 40], 'approximate_age'],
    'above clearance' => [['classification' => 'confidential'], 'classification'],
]);

test('validation failures never flash protected values into the session', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['lga_chief'])
        ->post('/persons', PersonFixtures::payload($world->lgas['A1a'], ['first_name' => '']))
        ->assertSessionHasErrors('first_name');

    $old = session()->getOldInput();

    expect($old)->toHaveKey('last_name')
        ->not->toHaveKey('nin')
        ->not->toHaveKey('phone')
        ->not->toHaveKey('address')
        ->not->toHaveKey('date_of_birth');
});

test('the person of interest role cannot be set through the persons forms', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];

    $this->actingAs($world->users['state_director'])
        ->post('/persons', PersonFixtures::payload($lga, ['roles' => [['role' => 'person_of_interest']]]))
        ->assertSessionHasErrors('roles.0.role');

    PersonFixtures::system();
    expect(Person::query()->count())->toBe(0);

    // Editing leaves a POI role (set by the 3B module) untouched.
    $person = Person::factory()->in($lga)->withRole(PersonRoleType::PERSON_OF_INTEREST)->create();
    $this->actingAs($world->users['state_director'])
        ->put("/persons/{$person->ulid}", PersonFixtures::payload($lga, ['lga' => null, 'confirm_new' => null, 'roles' => [['role' => 'political_leader']]]))
        ->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect($person->roles()->get()->sortBy(fn (PersonRole $r) => $r->typeCode())->values()->map(fn (PersonRole $r) => [$r->typeCode(), $r->is_active])->all())
        ->toBe([['person_of_interest', true], ['political_leader', true]]);
});

test('unchecking a role deactivates it, and checking it again reactivates it', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->withRole(PersonRoleType::TRADITIONAL_RULER)->create();
    $sd = $world->users['state_director'];
    $update = fn (array $roles) => $this->actingAs($sd)->put("/persons/{$person->ulid}", PersonFixtures::payload($lga, ['lga' => null, 'confirm_new' => null, 'roles' => $roles]))->assertSessionHasNoErrors();

    $update([]);
    PersonFixtures::system();
    expect(PersonRole::query()->sole()->is_active)->toBeFalse();

    $update([['role' => 'traditional_ruler', 'notes' => 'Restored']]);
    PersonFixtures::system();
    expect(PersonRole::query()->sole()->only(['is_active', 'notes']))->toBe(['is_active' => true, 'notes' => 'Restored'])
        ->and(Activity::query()->where('subject_type', PersonRole::class)->where('event', 'updated')->count())->toBe(2);
});

test('a person\'s LGA cannot be changed on edit', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();

    $this->actingAs($world->users['state_director'])
        ->put("/persons/{$person->ulid}", PersonFixtures::payload($world->lgas['A1b'], ['confirm_new' => null]))
        ->assertSessionHasErrors('lga');
});

test('editing cannot raise a person above the editor\'s clearance', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->create();
    $payload = PersonFixtures::payload($lga, ['lga' => null, 'confirm_new' => null, 'classification' => 'secret']);

    $this->actingAs($world->users['deputy_state_director'])->put("/persons/{$person->ulid}", $payload)->assertSessionHasErrors('classification');
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->put("/persons/{$person->ulid}", $payload)->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect($person->fresh()?->classification)->toBe(Classification::Secret)
        ->and(Activity::query()->where('event', 'classification_changed')->where('subject_id', $person->id)->sole()->properties->only(['old', 'new'])->all())
        ->toBe(['old' => 'restricted', 'new' => 'secret']);
});

/*
|--------------------------------------------------------------------------
| Duplicate warning on create
|--------------------------------------------------------------------------
*/

test('similar persons within jurisdiction are reported before creating', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $byName = Person::factory()->in($lga)->named('Babatunde', 'Adeyemi', 'Olusegun')->create();
    $byNin = Person::factory()->in($lga)->named('Someone', 'Else')->create(['nin' => PersonFixtures::SECRETS['nin']]);
    $byPhone = Person::factory()->in($lga)->named('Third', 'Person')->create(['phone' => '+2348035550199']);
    $byDob = Person::factory()->in($lga)->named('Fourth', 'Person')->create(['date_of_birth' => PersonFixtures::SECRETS['date_of_birth']]);
    $byAlias = Person::factory()->in($lga)->named('Fifth', 'Stranger')->create();
    $byAlias->aliases()->create(['alias' => 'Babatunde Olusegun Adeyemi']);
    // Same name, but outside the chief's jurisdiction: not reported (3C).
    Person::factory()->in($world->lgas['A1b'])->named('Babatunde', 'Adeyemi')->create();
    Person::factory()->in($lga)->named('Unrelated', 'Name')->create();

    $response = $this->actingAs($world->users['lga_chief'])->postJson('/persons/similar', [
        'first_name' => 'Babatunde',
        'last_name' => 'Adeyemy',
        'other_names' => 'Olusegun',
        'nin' => PersonFixtures::SECRETS['nin'],
        'phone' => '08035550199',
        'date_of_birth' => PersonFixtures::SECRETS['date_of_birth'],
    ])->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $matches = collect($response->json('matches'))->mapWithKeys(fn (array $m) => [$m['ulid'] => $m['reasons']]);

    expect($matches->keys()->sort()->values()->all())->toBe(collect([$byName, $byNin, $byPhone, $byDob, $byAlias])->pluck('ulid')->sort()->values()->all())
        ->and($matches[$byNin->ulid])->toBe(['Same NIN'])
        ->and($matches[$byPhone->ulid])->toBe(['Same phone number'])
        ->and($matches[$byName->ulid])->toBe(['Similar name'])
        ->and($matches[$byDob->ulid])->toBe(['Same date of birth'])
        ->and($matches[$byAlias->ulid])->toBe(['Similar name'])
        // NIN first (strongest).
        ->and($response->json('matches.0.ulid'))->toBe($byNin->ulid)
        // No protected values in the answer.
        ->and($response->getContent())->not->toContain(PersonFixtures::SECRETS['nin'])->not->toContain('8035550199');
});

test('creating needs confirmation when duplicates exist', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    Person::factory()->in($lga)->named('Babatunde', 'Adeyemi', 'Olusegun')->create();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($lga))->assertSessionHasErrors('confirm_new');
    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($lga, ['confirm_new' => true]))->assertSessionHasNoErrors();

    PersonFixtures::system();
    expect(Person::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

test('persons are found by NIN, by a phone written differently and by a misspelled name or alias', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $person = Person::factory()->in($lga)->named('Chukwuemeka', 'Okonkwo')->create(['nin' => '41234567890', 'phone' => '+2348035550199']);
    $person->aliases()->create(['alias' => 'Emeka Boat']);
    Person::factory()->in($lga)->named('Aisha', 'Bello')->create(['nin' => '41234567891']);
    // Out of jurisdiction: never found.
    Person::factory()->in($world->lgas['A1b'])->named('Chukwuemeka', 'Okonkwo')->create(['nin' => '41234567890']);

    $found = function (string $q) use ($world): array {
        return collect($this->actingAs($world->users['lga_chief'])->post('/persons/search', ['q' => $q])->assertOk()->inertiaProps('results'))->pluck('name')->all();
    };

    expect($found('41234567890'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('4123456789'))->toBe([])
        ->and($found('08035550199'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('+234 803 555 0199'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('2348035550199'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('Chukwuemka Okonkow'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('okonkwo'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('Emeka Boot'))->toBe(['Chukwuemeka Okonkwo'])
        ->and($found('Bello'))->toBe(['Aisha Bello'])
        ->and($found('Zzyzx Qwerty'))->toBe([]);
});

test('search filters and the recent list', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $ruler = Person::factory()->in($lga)->named('Oba', 'Ruler')->withRole(PersonRoleType::TRADITIONAL_RULER)->create(['gender' => 'male']);
    Person::factory()->in($lga)->named('Former', 'Ruler')->withRole(PersonRoleType::TRADITIONAL_RULER, active: false)->create();
    Person::factory()->count(22)->in($lga)->create();

    $sd = $world->users['state_director'];

    $this->actingAs($sd)->get('/persons')->assertInertia(fn (Assert $page) => $page
        ->component('persons/index')
        ->where('mode', 'recent')
        ->has('results', 20));

    $this->actingAs($sd)->post('/persons/search', ['role' => 'traditional_ruler'])->assertInertia(fn (Assert $page) => $page
        ->where('mode', 'filtered')
        ->has('results', 1)
        ->where('results.0.ulid', $ruler->ulid));

    $this->actingAs($sd)->post('/persons/search', ['gender' => 'male', 'lga' => $lga->ulid])->assertInertia(fn (Assert $page) => $page
        ->has('results', 1));

    // A state or LGA outside the jurisdiction is ignored, never widened.
    $this->actingAs($sd)->post('/persons/search', ['state' => $world->states['B1']->ulid, 'q' => 'Ruler'])->assertInertia(fn (Assert $page) => $page
        ->where('mode', 'name'));
});

test('searches are not GET requests', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['state_director'])->get('/persons/search?q=41234567890')->assertRedirect('/persons');
});

test('name search uses the trigram indexes', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];

    // Realistic volume, so the planner's own choice is tested (no planner
    // settings are changed): 50,000 persons and 5,000 aliases. Below
    // roughly 10,000 rows a sequential scan is cheaper, and PostgreSQL uses it.
    DB::statement(<<<'SQL'
        INSERT INTO persons (ulid, state_id, lga_id, first_name, last_name, gender, classification, created_at, updated_at)
        SELECT lpad(to_hex(g), 26, '0'), ?, ?, 'First' || g, 'Last' || md5(g::text), 'unknown', 'restricted', now(), now()
        FROM generate_series(1, 50000) g
    SQL, [$lga->state_id, $lga->id]);
    DB::statement(<<<'SQL'
        INSERT INTO person_aliases (ulid, person_id, alias, created_at, updated_at)
        SELECT lpad(to_hex(id), 26, '0'), id, 'Alias ' || md5(id::text), now(), now() FROM persons ORDER BY id LIMIT 5000
    SQL);
    Statistics::analyze('persons', 'person_aliases');

    $explain = fn (bool $noSeqScan) => app(JurisdictionContext::class)->actingAs($world->users['director_general'], fn () => PersonSearch::withThresholds(
        PersonSearch::SIMILARITY,
        PersonSearch::WORD_SIMILARITY,
        function () use ($noSeqScan) {
            if ($noSeqScan) {
                // Only bitmap scans remain: a condition can then be served
                // only by an index on that condition.
                DB::statement('SET LOCAL enable_seqscan = off');
                DB::statement('SET LOCAL enable_indexscan = off');
                DB::statement('SET LOCAL enable_indexonlyscan = off');
            }

            return collect(DB::select('EXPLAIN '.PersonSearch::byName(Person::query(), 'Okonkwo')->limit(50)->toRawSql()))
                ->map(fn ($row) => implode(' ', (array) $row))
                ->implode("\n");
        },
    ));

    // The planner's own choice at this volume: the name index.
    expect($explain(false))->toContain('Bitmap Index Scan on persons_search_name_trgm')
        ->and($explain(false))->not->toMatch('/Seq Scan on persons(?! \w)/');

    // Narrow alias rows stay cheaper to scan sequentially at test volumes
    // (and GIN estimates are rough there). With full scans ruled out, both
    // the name and the alias conditions are served by their trigram
    // indexes: the query is index-compatible for nationwide search (3C).
    $forced = $explain(true);

    expect($forced)->toContain('Bitmap Index Scan on persons_search_name_trgm')
        ->and($forced)->toContain('Bitmap Index Scan on person_aliases_search_alias_trgm');
});

/*
|--------------------------------------------------------------------------
| Viewing: access log, props, logs
|--------------------------------------------------------------------------
*/

test('every view of a person writes an access log entry', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->get("/persons/{$person->ulid}")->assertOk();
    $this->actingAs($chief)->get("/persons/{$person->ulid}?tab=history")->assertOk();

    PersonFixtures::system();
    $logs = RecordAccessLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$chief->id])
        ->and($logs->pluck('subject_id')->unique()->all())->toBe([$person->id])
        ->and($logs->pluck('context')->all())->toBe(['tab:profile', 'tab:history']);
});

test('the person page shows protected fields; other pages never carry them', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $person = Person::factory()->inCommunity($community)->named('Babatunde', 'Adeyemi')->withRole(PersonRoleType::TRADITIONAL_RULER)->create([
        'nin' => PersonFixtures::SECRETS['nin'],
        'phone' => PersonFixtures::SECRETS['phone_normalised'],
        'date_of_birth' => PersonFixtures::SECRETS['date_of_birth'],
        'address' => PersonFixtures::SECRETS['address'],
    ]);
    $community->forceFill(['traditional_ruler_id' => $person->id])->save();
    $sd = $world->users['state_director'];

    $this->actingAs($sd)->get("/persons/{$person->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('persons/show')
        ->where('person.nin', PersonFixtures::SECRETS['nin'])
        ->where('person.phone', PersonFixtures::SECRETS['phone_normalised'])
        ->where('person.address', PersonFixtures::SECRETS['address']));

    PersonFixtures::system();
    $indexes = DB::table('blind_indexes')->pluck('value')->all();

    foreach ([
        '/persons',
        "/communities/{$community->ulid}",
        "/communities/{$community->ulid}/edit",
        "/persons/{$person->ulid}?tab=history",
    ] as $url) {
        $body = $this->actingAs($sd)->get($url)->assertOk()->getContent();
        $body = $url === "/persons/{$person->ulid}?tab=history" ? json_encode($this->actingAs($sd)->get($url)->inertiaProps('history')) : $body;

        foreach ([PersonFixtures::SECRETS['nin'], '8035550199', PersonFixtures::SECRETS['date_of_birth'], PersonFixtures::SECRETS['address'], ...$indexes] as $secret) {
            expect((string) $body)->not->toContain($secret, "{$url} leaks a protected value");
        }
    }

    $search = $this->actingAs($sd)->post('/persons/search', ['q' => PersonFixtures::SECRETS['nin']])->getContent();
    expect((string) $search)->not->toContain('8035550199')->not->toContain(PersonFixtures::SECRETS['address']);
});

test('protected values never reach the application log', function () {
    $messages = [];
    Log::listen(function ($event) use (&$messages) {
        $messages[] = $event->message.' '.json_encode($event->context);
    });

    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($lga))->assertSessionHasNoErrors();
    $this->actingAs($chief)->post('/persons', PersonFixtures::payload($lga, ['nin' => 'bad']))->assertSessionHasErrors('nin');
    $this->actingAs($chief)->post('/persons/search', ['q' => PersonFixtures::SECRETS['nin']]);
    $this->actingAs($chief)->postJson('/persons/similar', ['first_name' => 'A', 'last_name' => 'B', 'nin' => PersonFixtures::SECRETS['nin']]);

    $all = implode("\n", $messages);

    foreach ([PersonFixtures::SECRETS['nin'], '8035550199', PersonFixtures::SECRETS['address'], PersonFixtures::SECRETS['date_of_birth']] as $secret) {
        expect($all)->not->toContain($secret);
    }
});

/*
|--------------------------------------------------------------------------
| Deletion
|--------------------------------------------------------------------------
*/

test('deleting a person needs State Director or above and a reason', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create(['nin' => PersonFixtures::SECRETS['nin']]);

    $this->actingAs($world->users['deputy_state_director'])->delete("/persons/{$person->ulid}", ['reason' => 'Duplicate'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['lga_chief'])->delete("/persons/{$person->ulid}", ['reason' => 'Duplicate'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete("/persons/{$person->ulid}", [])->assertSessionHasErrors('reason');
    $this->actingAs($world->users['state_director'])->delete("/persons/{$person->ulid}", ['reason' => 'Duplicate of another record'])->assertRedirect('/persons');

    PersonFixtures::system();
    $row = DB::table('persons')->where('id', $person->id)->first();

    expect($row?->deleted_at)->not->toBeNull()
        ->and($row?->nin)->toStartWith('nacl:')
        ->and(Activity::query()->where('event', 'person_deleted')->sole()->properties['reason'])->toBe('Duplicate of another record')
        ->and(Person::query()->whereKey($person->id)->exists())->toBeFalse();

    $this->flushSession();
    $this->actingAs($world->users['state_director'])->get("/persons/{$person->ulid}")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Aliases and history
|--------------------------------------------------------------------------
*/

test('aliases are added, de-duplicated, removed and shown in the person\'s history', function () {
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/persons/{$person->ulid}/aliases", ['alias' => 'Big Man'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->post("/persons/{$person->ulid}/aliases", ['alias' => '  big  MAN '])->assertSessionHasErrors('alias');

    PersonFixtures::system();
    $alias = PersonAlias::query()->sole();
    $this->actingAs($chief)->delete("/persons/{$person->ulid}/aliases/{$alias->ulid}")->assertRedirect();

    PersonFixtures::system();
    expect(PersonAlias::query()->count())->toBe(0)
        ->and(PersonAlias::withTrashed()->count())->toBe(1);

    $history = collect($this->actingAs($chief)->get("/persons/{$person->ulid}?tab=history")->inertiaProps('history.data'));

    expect($history->pluck('event')->all())->toContain('alias_removed')->toContain('created')
        ->and($history->pluck('subject_type')->all())->toContain('PersonAlias');
});
