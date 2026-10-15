<?php

use App\Actions\Persons\UndoPersonMerge;
use App\Enums\AccessRequestStatus;
use App\Enums\Classification;
use App\Events\AccessGrantEnded;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\RecordAccessGrant;
use App\Models\User;
use App\Notifications\AccessGrantChanged;
use App\Notifications\PersonMergeNotification;
use App\Support\Audit\AuditChain;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\Merge\MergeBlocked;
use App\Support\Persons\Merge\MergeChoices;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\MergeFixtures;

/*
| Phase 10A.5 / 10A.6 / 10A.9: authorisation, grants and requests, history,
| the protection of survivor_prior_values, undo limits, concurrency and
| notifications.
*/

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    CrossFixtures::system();
});

/*
| Authorisation
*/

test('who may merge: State Directors and national users, own jurisdiction only', function (string $who, bool $allowed) {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1b'])->create();
    $attempt = fn () => MergeFixtures::merge($this->world->users[$who], $a, $b);

    if ($allowed) {
        expect($attempt()->survivor_person_id)->toBe($a->id);
    } else {
        expect($attempt)->toThrow(AuthorizationException::class);
        expect(MergeFixtures::reload($b)->trashed())->toBeFalse();
    }
})->with([
    ['director_general', true],
    ['ddg_national', true],
    ['ddg_zone_a', true],
    ['ddg_zone_b', false],
    ['state_director', true],
    ['deputy_state_director', false],
    ['lga_chief', false],
    ['super_admin', false],
]);

test('a State Director of another state may not merge', function () {
    $a = Person::factory()->in($this->world->lgas['B1'])->create();
    $b = Person::factory()->in($this->world->lgas['B1'])->create();

    expect(fn () => MergeFixtures::merge($this->sd, $a, $b))->toThrow(AuthorizationException::class);
});

test('records in different states are never merged, even by the DG', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A2'])->create();

    expect(fn () => MergeFixtures::merge($this->world->users['director_general'], $a, $b))->toThrow(MergeBlocked::class, 'different states');
});

test('both records must be within the executor\'s clearance, never through a grant', function () {
    $sd = $this->sd;
    $holder = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    // Created by the deputy: secret, visible to its creator and the SD only.
    $deputy = $this->world->users['deputy_state_director'];
    $secret = Person::factory()->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create(['created_by' => $deputy->id]);
    $open = Person::factory()->in($this->world->lgas['A1a'])->create();

    expect(app(JurisdictionContext::class)->actingAs($sd, fn () => $sd->can('merge', MergeFixtures::reload($secret))))->toBeTrue()
        ->and(app(JurisdictionContext::class)->actingAs($holder, fn () => $holder->can('merge', MergeFixtures::reload($open))))->toBeFalse();
});

/*
| Grants, requests, history
*/

test('grants on the source are revoked with a live end event, open requests cancelled, nothing transferred', function () {
    Event::fake([AccessGrantEnded::class]);
    Notification::fake();
    $kano = CrossFixtures::directorOfB1($this->world);
    $source = CrossFixtures::person($this->world->lgas['A1b']);
    $survivor = Person::factory()->in($this->world->lgas['A1a'])->named('Garba', 'Musa')->create();
    $holder = CrossFixtures::chiefOf($this->world->lgas['B1']);
    $requester = CrossFixtures::chiefOf(Lga::factory()->create(['state_id' => $this->world->states['B1']->id]));

    $grant = app(JurisdictionContext::class)->actingAs($holder, fn () => CrossFixtures::grant($holder, $this->sd));
    $request = app(JurisdictionContext::class)->actingAs($requester, fn () => CrossFixtures::request($requester));

    MergeFixtures::merge($this->sd, $survivor, $source);

    $grant->refresh();
    expect($grant->revoked_at)->not->toBeNull()
        ->and($grant->revocation_reason)->toBe('record merged')
        ->and($grant->subject_id)->toBe($source->id)
        ->and($request->fresh()?->status)->toBe(AccessRequestStatus::Cancelled)
        ->and($request->fresh()?->decision_note)->toBe('record merged')
        ->and(RecordAccessGrant::query()->where('subject_id', $survivor->id)->exists())->toBeFalse()
        ->and(app(JurisdictionContext::class)->actingAs($holder, fn () => Person::query()->whereKey($survivor->id)->exists()))->toBeFalse();

    Event::assertDispatched(AccessGrantEnded::class, fn (AccessGrantEnded $e) => $e->grantUlid === $grant->ulid && $e->holderUlid === $holder->ulid);
    Notification::assertSentTo($holder, AccessGrantChanged::class, fn ($n) => $n->change === 'revoked');
    Notification::assertSentTo($requester, PersonMergeNotification::class, fn ($n) => $n->kind === PersonMergeNotification::ACCESS_REQUEST_CANCELLED);
    expect($kano)->toBeInstanceOf(User::class);
});

test('history stays attached to the source ID', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->create();
    app(JurisdictionContext::class)->actingAs($this->sd, fn () => MergeFixtures::reload($b)->recordAccess(context: 'test'));
    $sourceEvents = Activity::query()->where('subject_type', Person::class)->where('subject_id', $b->id)->count();

    $merge = MergeFixtures::merge($this->sd, $a, $b);

    expect(Activity::query()->where('subject_type', Person::class)->where('subject_id', $b->id)->count())->toBe($sourceEvents + 1)
        ->and(Activity::query()->where('subject_id', $b->id)->where('event', 'person_merged_into')->value('properties')->toArray())->toMatchArray(['merge' => $merge->ulid, 'survivor' => $a->ulid])
        ->and(Activity::query()->where('subject_id', $a->id)->where('event', 'person_merged_from')->exists())->toBeTrue()
        ->and(DB::table('record_access_logs')->where('subject_type', Person::class)->where('subject_id', $b->id)->count())->toBe(1);
});

/*
| survivor_prior_values
*/

test('survivor_prior_values is encrypted, never logged or serialised, left out of the hash, and erased', function () {
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.json_encode($message->context);
    });
    $a = Person::factory()->in($this->world->lgas['A1a'])->create(['phone' => '08039998888', 'address' => 'Unique Address 77']);
    $b = Person::factory()->in($this->world->lgas['A1a'])->create(['phone' => '08031117777']);

    $merge = MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(fields: ['phone' => 'source']));
    $raw = (string) DB::table('person_merges')->where('id', $merge->id)->value('survivor_prior_values');
    $values = Crypt::decrypt($raw);

    expect($raw)->not->toContain('Unique Address')
        ->and($values['before']['address'])->toBe('Unique Address 77')
        ->and($values['before']['phone'])->toBe('+2348039998888')
        ->and(array_key_exists('survivor_prior_values', $merge->toArray()))->toBeFalse()
        ->and(Activity::query()->get()->map(fn ($a) => json_encode($a->properties))->filter(fn ($p) => str_contains((string) $p, 'Unique Address') || str_contains((string) $p, 'survivor_prior_values') || str_contains((string) $p, '9998888'))->all())->toBe([])
        ->and(implode("\n", $logged))->not->toContain('Unique Address')
        ->and(AuditChain::verify('person_merges')['ok'])->toBeTrue();

    // Erased by the daily job only after the window (or an undo).
    $this->artisan('buffalo:merges:erase-prior-values')->assertSuccessful();
    expect(DB::table('person_merges')->where('id', $merge->id)->value('survivor_prior_values'))->not->toBeNull();

    $this->travel(31)->days();
    $this->artisan('buffalo:merges:erase-prior-values')->assertSuccessful();
    expect(DB::table('person_merges')->where('id', $merge->id)->value('survivor_prior_values'))->toBeNull()
        ->and(AuditChain::verify('person_merges')['ok'])->toBeTrue()
        ->and(fn () => MergeFixtures::undo($this->sd, $merge))->toThrow(MergeBlocked::class, 'undo window');
});

test('person_merges is append-only except the undo columns', function () {
    $merge = MergeFixtures::merge($this->sd, Person::factory()->in($this->world->lgas['A1a'])->create(), Person::factory()->in($this->world->lgas['A1a'])->create());
    expect(fn () => DB::transaction(fn () => DB::table('person_merges')->where('id', $merge->id)->update(['manifest' => '{}'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('person_merges')->where('id', $merge->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('person_merges')->where('id', $merge->id)->update(['survivor_prior_values' => 'replaced'])))->toThrow(QueryException::class)
        // TRUNCATE: not granted to the application, and refused by a trigger for the owner.
        ->and(DB::selectOne("SELECT has_table_privilege('buffalo_app', 'person_merges', 'TRUNCATE') AS t")->t)->toBeFalse()
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgrelid = 'person_merges'::regclass AND tgname = 'person_merges_no_truncate'")->n)->toBe(1);
});

/*
| Undo limits
*/

test('undo is refused after the window, twice, or after the survivor was merged again', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->create();
    $c = Person::factory()->in($this->world->lgas['A1a'])->create();

    $first = MergeFixtures::merge($this->sd, $a, $b);
    $second = MergeFixtures::merge($this->sd, $c, $a);

    expect(fn () => MergeFixtures::undo($this->sd, $first))->toThrow(MergeBlocked::class, 'since been merged');

    MergeFixtures::undo($this->sd, $second);
    expect(fn () => MergeFixtures::undo($this->sd, $second))->toThrow(MergeBlocked::class, 'already been undone');

    $this->travel(31)->days();
    expect(fn () => MergeFixtures::undo($this->sd, $first))->toThrow(MergeBlocked::class, 'undo window');
});

test('a merged person is never a survivor, and a later merge into the survivor blocks undoing the earlier one', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->create();
    $c = Person::factory()->in($this->world->lgas['A1a'])->create();

    $first = MergeFixtures::merge($this->sd, $a, $b);

    expect(fn () => MergeFixtures::merge($this->sd, $b, $c))->toThrow(MergeBlocked::class, 'already been merged')
        ->and(MergeFixtures::reload($c)->trashed())->toBeFalse();

    MergeFixtures::merge($this->sd, $a, $c);
    expect(fn () => MergeFixtures::undo($this->sd, $first))->toThrow(MergeBlocked::class, 'Undo that merge first');
});

test('an undo that cannot move every row back changes nothing', function () {
    ['survivor' => $survivor, 'source' => $source, 'ids' => $ids] = MergeFixtures::rich($this->world);
    $merge = MergeFixtures::merge($this->sd, $survivor, $source);
    $moved = collect($merge->manifest['entries'])->first(fn ($e) => $e['t'] === 'political_offices');
    $elsewhere = Person::factory()->in($this->world->lgas['A1a'])->create();
    // Reassigned to someone else after the merge.
    DB::table('political_offices')->where('id', $moved['id'])->update(['person_id' => $elsewhere->id]);
    $rows = MergeFixtures::rows($ids);

    expect(fn () => MergeFixtures::undo($this->sd, $merge))->toThrow(MergeBlocked::class, 'moved since the merge');

    expect(MergeFixtures::rows($ids))->toEqual($rows)
        ->and(MergeFixtures::reload($source)->trashed())->toBeTrue()
        ->and($merge->fresh()?->undone_at)->toBeNull();
});

test('survivor edits made after the merge are kept or discarded as chosen', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create(['occupation' => 'Trader', 'description' => 'Tall']);
    $b = Person::factory()->in($this->world->lgas['A1a'])->create(['occupation' => 'Farmer']);
    $merge = MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(fields: ['occupation' => 'source']));
    // The description is rich text since Phase 11B.
    DB::table('persons')->where('id', $a->id)->update(['occupation' => 'Mechanic', 'description' => '<p>Tall, scar on left arm</p>', 'description_text' => 'Tall, scar on left arm']);

    $edits = UndoPersonMerge::editsSinceMerge($merge->fresh(), MergeFixtures::reload($a));
    expect(array_column($edits, 'field'))->toBe(['occupation', 'description'])
        ->and($edits[0]['before_merge'])->toBe('Trader');

    MergeFixtures::undo($this->sd, $merge, keep: ['description']);

    $restored = MergeFixtures::reload($a);
    expect([$restored->occupation, $restored->description])->toBe(['Trader', '<p>Tall, scar on left arm</p>']);
});

/*
| Concurrency
*/

test('two merges involving the same person cannot run at once', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->create();
    $c = Person::factory()->in($this->world->lgas['A1a'])->create();
    config(['database.connections.pgsql_concurrent' => config('database.connections.pgsql'), 'buffalo.merges.lock_timeout' => '500ms']);
    $other = DB::connection('pgsql_concurrent');

    // Another session is merging (or undoing) a merge that involves $b.
    $other->statement('SELECT pg_advisory_lock(10001, ?)', [$b->id]);

    try {
        expect(fn () => MergeFixtures::merge($this->sd, $a, $b))->toThrow(MergeBlocked::class, 'in progress')
            ->and(MergeFixtures::reload($b)->trashed())->toBeFalse();
    } finally {
        $other->statement('SELECT pg_advisory_unlock(10001, ?)', [$b->id]);
        $other->disconnect();
    }

    // Once the first has committed, the second sees the record merged.
    MergeFixtures::merge($this->sd, $a, $b);
    expect(fn () => MergeFixtures::merge($this->sd, $c, $b))->toThrow(MergeBlocked::class, 'already been merged')
        ->and(PersonMerge::query()->withoutGlobalScopes()->where('source_person_id', $b->id)->count())->toBe(1);
});

/*
| Notifications
*/

test('notifications go to both LGAs and the State Director, without names', function () {
    Notification::fake();
    $chiefA = $this->world->users['lga_chief'];
    $chiefB = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    $a = Person::factory()->in($this->world->lgas['A1a'])->named('Zainab', 'Lawal')->create();
    $b = Person::factory()->in($this->world->lgas['A1b'])->named('Zainab', 'Lawali')->create();

    $merge = MergeFixtures::merge($this->sd, $a, $b);
    MergeFixtures::undo($this->sd, $merge);

    foreach ([$chiefA, $chiefB] as $chief) {
        Notification::assertSentTo($chief, PersonMergeNotification::class, function (PersonMergeNotification $n) use ($chief, $a) {
            $payload = json_encode($n->toArray($chief));

            return ! str_contains((string) $payload, 'Zainab') && ! str_contains((string) $payload, 'Lawal') && $n->url($chief) === '/persons/'.$a->ulid;
        });
    }
    Notification::assertSentToTimes($chiefA, PersonMergeNotification::class, 2);
    Notification::assertNotSentTo($this->sd, PersonMergeNotification::class);
});
