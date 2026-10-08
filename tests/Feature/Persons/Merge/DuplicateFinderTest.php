<?php

use App\Enums\Classification;
use App\Enums\DuplicateStrength;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\Merge\DuplicateCounts;
use App\Support\Persons\Merge\PersonDuplicateFinder;
use App\Support\Search\Trigram;
use Illuminate\Support\Facades\DB;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Phase 10A.3: duplicate candidates, own jurisdiction only.
 *
 * @return list<string> "First Last|First Last|strength|signals"
 */
function candidatesFor(User $user): array
{
    return app(JurisdictionContext::class)->actingAs($user->fresh() ?? $user, fn () => array_map(
        fn (array $pair) => "{$pair['a']->first_name} {$pair['a']->last_name}|{$pair['b']->first_name} {$pair['b']->last_name}|{$pair['strength']->value}|".implode(',', $pair['signals']),
        app(PersonDuplicateFinder::class)->forUser($user),
    ));
}

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    CrossFixtures::system();
});

test('each signal produces its strength', function () {
    $lga = $this->world->lgas['A1a'];
    $other = $this->world->lgas['A1b'];

    Person::factory()->in($lga)->named('Nin', 'One')->create(['nin' => '11111111111']);
    Person::factory()->in($other)->named('Different', 'Name')->create(['nin' => '11111111111']);
    Person::factory()->in($lga)->named('Phone', 'Owner')->create(['phone' => '08031234567']);
    Person::factory()->in($other)->named('Another', 'Holder')->create(['phone' => '+2348031234567']);
    Person::factory()->in($lga)->named('Abubakar', 'Mohammed')->create(['date_of_birth' => '1980-05-01']);
    Person::factory()->in($other)->named('Abubakar', 'Mohamed')->create(['date_of_birth' => '1980-05-01']);
    // Same DOB, names below 0.75 similarity (0.71): no candidate.
    Person::factory()->in($lga)->named('Sani', 'Mohammed')->create(['date_of_birth' => '1982-02-02']);
    Person::factory()->in($other)->named('Sani', 'Muhammed')->create(['date_of_birth' => '1982-02-02']);
    // Same DOB, unrelated names: no candidate.
    Person::factory()->in($lga)->named('Chinedu', 'Okafor')->create(['date_of_birth' => '1975-01-01']);
    Person::factory()->in($other)->named('Yusuf', 'Bello')->create(['date_of_birth' => '1975-01-01']);
    // Similar names in the same LGA: possible; in another LGA: nothing.
    Person::factory()->in($lga)->named('Oluwaseun', 'Adeyemi')->create();
    Person::factory()->in($lga)->named('Oluwaseun', 'Adeyemii')->create();
    Person::factory()->in($other)->named('Oluwaseun', 'Adeyemi')->create();

    expect(candidatesFor($this->world->users['state_director']))->toBe([
        'Nin One|Different Name|certain|nin',
        'Abubakar Mohammed|Abubakar Mohamed|strong|dob',
        'Phone Owner|Another Holder|strong|phone',
        'Oluwaseun Adeyemi|Oluwaseun Adeyemii|possible|name',
    ]);
});

test('an alias counts as a name', function () {
    $lga = $this->world->lgas['A1a'];
    $a = Person::factory()->in($lga)->named('Ibrahim', 'Garba')->create();
    Person::factory()->in($lga)->named('Ibrahim', 'Sule')->create();
    PersonAlias::query()->forceCreate(['person_id' => $a->id, 'alias' => 'Ibrahim Sule']);

    expect(candidatesFor($this->world->users['lga_chief']))->toBe(['Ibrahim Garba|Ibrahim Sule|possible|name']);
});

test('candidates are own jurisdiction only, per role', function () {
    Person::factory()->in($this->world->lgas['A1a'])->named('Own', 'Lga')->create(['nin' => '22222222222']);
    Person::factory()->in($this->world->lgas['A1b'])->named('Same', 'State')->create(['nin' => '22222222222']);
    Person::factory()->in($this->world->lgas['A2'])->named('Zone', 'A2')->create(['nin' => '33333333333']);
    Person::factory()->in($this->world->lgas['A2'])->named('Zone', 'Twin')->create(['nin' => '33333333333']);

    expect(candidatesFor($this->world->users['lga_chief']))->toBe([])
        ->and(candidatesFor($this->world->users['state_director']))->toBe(['Own Lga|Same State|certain|nin'])
        ->and(candidatesFor($this->world->users['deputy_state_director']))->toBe(['Own Lga|Same State|certain|nin'])
        ->and(candidatesFor($this->world->users['ddg_zone_b']))->toBe([])
        ->and(candidatesFor($this->world->users['director_general']))->toHaveCount(2)
        ->and(candidatesFor($this->world->users['super_admin']))->toBe([]);
});

test('pairs in different states are never candidates', function () {
    Person::factory()->in($this->world->lgas['A1a'])->named('Garba', 'Musa')->create(['nin' => '44444444444', 'date_of_birth' => '1990-01-01']);
    Person::factory()->in($this->world->lgas['B1'])->named('Garba', 'Musa')->create(['nin' => '44444444444', 'date_of_birth' => '1990-01-01']);

    expect(candidatesFor($this->world->users['director_general']))->toBe([]);
});

test('records seen only under a grant never contribute', function () {
    $chief = $this->world->users['lga_chief'];
    // The A1b record (another LGA) is visible to the A1a chief only under a grant.
    CrossFixtures::person($this->world->lgas['A1b']);
    Person::factory()->in($this->world->lgas['A1a'])->named('Garba', 'Musa', 'Danjuma')->create(['nin' => CrossFixtures::NIN]);
    $grant = app(JurisdictionContext::class)->actingAs($chief, fn () => CrossFixtures::grant($chief, $this->world->users['state_director']));

    expect($grant->revoked_at)->toBeNull()
        ->and(app(JurisdictionContext::class)->actingAs($chief, fn () => Person::query()->count()))->toBe(2)
        ->and(candidatesFor($chief))->toBe([])
        ->and(candidatesFor($this->world->users['state_director']))->toHaveCount(1);
});

test('classification applies: a deputy never sees a pair with a secret record', function () {
    Person::factory()->in($this->world->lgas['A1a'])->named('Open', 'Record')->create(['nin' => '55555555555']);
    Person::factory()->in($this->world->lgas['A1b'])->classified(Classification::Secret)->named('Secret', 'Record')->create(['nin' => '55555555555']);

    expect(candidatesFor($this->world->users['deputy_state_director']))->toBe([])
        ->and(candidatesFor($this->world->users['state_director']))->toHaveCount(1);
});

test('dismissed, merged and deleted records are excluded', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->named('Pair', 'One')->create(['nin' => '66666666666']);
    $b = Person::factory()->in($this->world->lgas['A1a'])->named('Pair', 'Two')->create(['nin' => '66666666666']);
    $c = Person::factory()->in($this->world->lgas['A1a'])->named('Pair', 'Three')->create(['nin' => '66666666666']);
    $sd = $this->world->users['state_director'];

    expect(candidatesFor($sd))->toHaveCount(3);

    DB::table('person_duplicate_dismissals')->insert(['person_a_id' => min($a->id, $b->id), 'person_b_id' => max($a->id, $b->id), 'dismissed_by' => $sd->id, 'reason' => 'Twins: different people.', 'created_at' => now()]);
    expect(candidatesFor($sd))->toEqualCanonicalizing(['Pair One|Pair Three|certain|nin', 'Pair Two|Pair Three|certain|nin']);

    $c->delete();
    expect(candidatesFor($sd))->toBe([]);
});

test('the finder stays fast at volume and uses the blind index', function () {
    $lga = $this->world->lgas['A1a'];
    $sd = $this->world->users['state_director'];
    // 20,000 persons in ONE LGA (the worst case for name pairs), with common
    // first names, 4,000 aliases and 6,000 blind index rows (synthetic
    // values; nothing is decrypted here).
    DB::statement(<<<'SQL'
        INSERT INTO persons (ulid, state_id, lga_id, first_name, last_name, gender, nationality, classification, is_deceased, created_at, updated_at)
        SELECT upper(substr(md5(g::text), 1, 26)), ?, ?,
               (ARRAY['Musa','Ibrahim','Abubakar','Aisha','Fatima','Chinedu','Emeka','Ngozi','Oluwaseun','Adebayo','Yusuf','Sani','Bello','Amina','Halima','Tunde','Segun','Kemi','Ifeanyi','Uche'])[1 + g % 20],
               initcap(translate(substr(encode(sha256(g::text::bytea), 'base64'), 1, 9), '0123456789+/', 'aeiouaeiouyw')),
               'unknown', 'Nigerian', 'restricted', false, now(), now()
          FROM generate_series(1, 20000) g
        SQL, [$lga->state_id, $lga->id]);
    DB::statement(<<<'SQL'
        INSERT INTO person_aliases (ulid, person_id, alias, created_at, updated_at)
        SELECT upper(substr(md5('a' || id::text), 1, 26)), id, initcap(translate(substr(encode(sha256(('a' || id)::bytea), 'base64'), 1, 12), '0123456789+/', 'aeiouaeiouyw')), now(), now()
          FROM persons WHERE id % 5 = 0
        SQL);
    DB::statement(<<<'SQL'
        INSERT INTO blind_indexes (indexable_type, indexable_id, name, value)
        SELECT 'App\Models\Person', id, n, md5(n || (id % 9000)::text) FROM persons, unnest(ARRAY['nin_bidx', 'phone_bidx', 'dob_bidx']) n WHERE id % 10 = 0
        SQL);
    foreach (['persons', 'person_aliases', 'blind_indexes'] as $table) {
        DB::statement("ANALYZE {$table}");
    }

    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        if (str_contains($query->sql, 'WITH scoped AS MATERIALIZED')) {
            $captured ??= [$query->sql, $query->bindings, $query->time];
        }
    });
    $pairs = candidatesFor($sd);

    [$sql, $bindings, $ms] = $captured;
    $plan = implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, Trigram::withThresholds(0.85, 1.0, fn () => DB::select("EXPLAIN {$sql}", $bindings))));

    expect($pairs)->toHaveCount(200)
        ->and($ms)->toBeLessThan(15000)
        ->and($plan)->toMatch('/Index (Only )?Scan using blind_indexes_\w+ on blind_indexes/')
        ->and($plan)->not->toContain('Seq Scan on blind_indexes');
})->group('slow');

test('candidate strengths are ordered', function () {
    expect(DuplicateStrength::Certain->rank())->toBeGreaterThan(DuplicateStrength::Strong->rank())
        ->and(DuplicateStrength::Strong->rank())->toBeGreaterThan(DuplicateStrength::Possible->rank());
});

test('the nightly job counts candidates per state for the State Director\'s dashboard (counts only)', function () {
    Person::factory()->in($this->world->lgas['A1a'])->named('Nin', 'One')->create(['nin' => '77777777777']);
    Person::factory()->in($this->world->lgas['A1b'])->classified(Classification::Secret)->named('Nin', 'Two')->create(['nin' => '77777777777']);
    Person::factory()->in($this->world->lgas['A2'])->named('Zone', 'One')->create(['phone' => '08030001111']);
    Person::factory()->in($this->world->lgas['A2'])->named('Zone', 'Two')->create(['phone' => '08030001111']);

    $this->artisan('buffalo:persons:count-duplicates')->assertSuccessful();

    expect(DuplicateCounts::forState($this->world->states['A1']->id))->toMatchArray(['certain' => 1, 'strong' => 0, 'possible' => 0, 'total' => 1])
        ->and(DuplicateCounts::forState($this->world->states['A2']->id))->toMatchArray(['strong' => 1, 'total' => 1]);

    $sd = CrossFixtures::signIn($this->world->users['state_director'])->get('/dashboard');
    $sd->assertInertia(fn ($page) => $page->where('duplicates.nightly.total', 1)->where('duplicates.pendingRequests', 0));
    // A deputy's view excludes secret records, so the state count is not shown to them.
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('duplicates', null));
});
