<?php

use App\Enums\TaskingStatus;
use App\Enums\TaskingTargetMode;
use App\Models\Lga;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Notifications\TaskingNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Taskings\TaskingFixtures;

/*
| Phase 11H.1–2 and 11H.6: sending taskings through the one form and
| picker. State A1 has LGAs A1a and A1b (plus a third added here, and an
| inactive one), so one, some and all can be told apart.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $state = $this->world->states['A1'];
    $this->lgas = [
        'A1a' => $this->world->lgas['A1a'],
        'A1b' => $this->world->lgas['A1b'],
        'A1c' => Lga::factory()->create(['state_id' => $state->id, 'name' => 'Ikot Ekpene']),
    ];
    $this->inactive = Lga::factory()->create(['state_id' => $state->id, 'name' => 'Old LGA', 'is_active' => false]);
    $this->sd = $this->world->users['state_director'];
    $this->chiefs = [
        'A1a' => TaskingFixtures::chief($this->lgas['A1a']),
        'A1b' => TaskingFixtures::chief($this->lgas['A1b']),
    ];
    // A1c is vacant: no Security Chief assigned.
});

function sendTasking(array $lgas, array $data = []): TestResponse
{
    return CrossFixtures::signIn(test()->sd)->post('/taskings', [
        'send' => true,
        'title' => 'Market patrols',
        'body' => '<p>Increase patrols on the market roads.</p>',
        'priority' => 'priority',
        'response_due_at' => null,
        'classification' => 'restricted',
        'lgas' => array_map(fn (Lga $l) => $l->ulid, $lgas),
        ...$data,
    ]);
}

test('one form sends to one LGA, some LGAs or all LGAs, and derives the target mode', function () {
    $active = Lga::query()->active()->where('state_id', $this->sd->state_id)->get();

    foreach ([
        [[$this->lgas['A1a']], TaskingTargetMode::Single],
        [[$this->lgas['A1a'], $this->lgas['A1c']], TaskingTargetMode::Selected],
        [$active->all(), TaskingTargetMode::AllLgas],
    ] as [$lgas, $mode]) {
        sendTasking($lgas)->assertRedirect();
        CrossFixtures::system();
        $tasking = Tasking::query()->latest('id')->firstOrFail();

        expect($tasking->status)->toBe(TaskingStatus::Sent)
            ->and($tasking->target_mode)->toBe($mode)
            ->and(TaskingRecipient::query()->where('tasking_id', $tasking->id)->pluck('lga_id')->sort()->values()->all())
            ->toBe(collect($lgas)->pluck('id')->sort()->values()->all());
    }

    // "All" means every ACTIVE LGA of the state at send time.
    expect($active->pluck('id'))->not->toContain($this->inactive->id);
});

test('references are TSK/<state>/<year>/<5 digits>, sequential per state and year', function () {
    sendTasking([$this->lgas['A1a']]);
    sendTasking([$this->lgas['A1b']]);
    CrossFixtures::system();
    $code = strtoupper((string) $this->world->states['A1']->code);
    $year = now()->setTimezone('Africa/Lagos')->format('Y');

    expect(Tasking::query()->orderBy('id')->pluck('reference_no')->all())
        ->toBe(["TSK/{$code}/{$year}/00001", "TSK/{$code}/{$year}/00002"]);
});

test('at least one LGA is required to send, and a draft may have none', function () {
    sendTasking([])->assertSessionHasErrors('lgas');
    CrossFixtures::system();
    expect(Tasking::query()->count())->toBe(0);

    CrossFixtures::signIn($this->sd)->post('/taskings', [
        'title' => 'Draft', 'body' => '', 'priority' => 'routine', 'classification' => 'restricted', 'lgas' => [],
    ])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect(Tasking::query()->sole()->status)->toBe(TaskingStatus::Draft);
});

test('recipients come only from the sender\'s own state: a forged LGA is refused', function () {
    sendTasking([$this->lgas['A1a'], $this->world->lgas['B1']])->assertSessionHasErrors('lgas.1');
    sendTasking([$this->inactive])->assertSessionHasErrors('lgas.0');
    CrossFixtures::system();

    expect(Tasking::query()->count())->toBe(0);
});

test('the send action re-checks recipients inside its transaction', function () {
    $draft = TaskingFixtures::draft($this->sd);

    expect(fn () => TaskingFixtures::sent($this->sd, [$this->world->lgas['B1']]))
        ->toThrow(ValidationException::class);
    CrossFixtures::system();
    expect($draft->fresh()?->status)->toBe(TaskingStatus::Draft)
        ->and(DB::table('tasking_recipients')->count())->toBe(0);
});

test('the body is required to send and limited by its plain text', function () {
    sendTasking([$this->lgas['A1a']], ['body' => '<p> </p>'])->assertSessionHasErrors('body');

    config(['buffalo.taskings.max_characters' => 20]);
    sendTasking([$this->lgas['A1a']], ['body' => '<p><strong>'.str_repeat('a', 21).'</strong></p>'])->assertSessionHasErrors('body');
    sendTasking([$this->lgas['A1a']], ['body' => '<p><strong>'.str_repeat('a', 20).'</strong></p>'])->assertSessionHasNoErrors();
});

test('the default limit leaves room for long instructions', function () {
    expect((int) config('buffalo.taskings.max_characters'))->toBe(50000);
    sendTasking([$this->lgas['A1a']], ['body' => '<p>'.str_repeat('Patrol the roads. ', 1200).'</p>'])->assertSessionHasNoErrors();
});

test('sending is refused when LGA chiefs could not see the classification; a draft may keep it', function () {
    sendTasking([$this->lgas['A1a']], ['classification' => 'confidential'])->assertSessionHasErrors('classification');

    CrossFixtures::signIn($this->sd)->post('/taskings', [
        'title' => 'Draft', 'body' => '<p>x</p>', 'priority' => 'routine', 'classification' => 'confidential', 'lgas' => [$this->lgas['A1a']->ulid],
    ])->assertSessionHasNoErrors();
    CrossFixtures::system();
    $draft = Tasking::query()->sole();

    CrossFixtures::signIn($this->sd)->put("/taskings/{$draft->ulid}", [
        'send' => true, 'title' => 'Draft', 'body' => '<p>x</p>', 'priority' => 'routine', 'classification' => 'confidential', 'lgas' => [$this->lgas['A1a']->ulid],
    ])->assertSessionHasErrors('classification');
    CrossFixtures::system();
    expect($draft->fresh()?->status)->toBe(TaskingStatus::Draft);
});

test('only the state\'s directors send; the deputy may too', function () {
    $dsd = $this->world->users['deputy_state_director'];
    CrossFixtures::signIn($dsd)->get('/taskings/create')->assertOk();

    foreach (['lga_chief', 'director_general', 'ddg_zone_a', 'super_admin'] as $role) {
        CrossFixtures::signIn($this->world->users[$role])->get('/taskings/create')->assertForbidden();
        CrossFixtures::signIn($this->world->users[$role])->post('/taskings', [
            'send' => true, 'body' => '<p>x</p>', 'priority' => 'routine', 'classification' => 'restricted', 'lgas' => [$this->lgas['A1a']->ulid],
        ])->assertForbidden();
    }
});

test('the picker lists every active LGA of the state with its current chief; vacant ones are named', function () {
    $props = CrossFixtures::signIn($this->sd)->get('/taskings/create')->assertOk()->inertiaProps();
    $lgas = collect($props['lgas'])->keyBy('name');

    expect($lgas->keys()->sort()->values()->all())->toBe(collect($this->lgas)->pluck('name')->sort()->values()->all())
        ->and($lgas[$this->lgas['A1a']->name]['chief'])->toBe($this->chiefs['A1a']->name)
        ->and($lgas['Ikot Ekpene']['chief'])->toBeNull();
});

test('the LGA page pre-ticks its LGA; another state\'s LGA cannot be pre-ticked', function () {
    $props = CrossFixtures::signIn($this->sd)->get('/taskings/create?lga='.$this->lgas['A1b']->ulid)->assertOk()->inertiaProps();
    expect($props['chosen'])->toBe([$this->lgas['A1b']->ulid]);

    CrossFixtures::signIn($this->sd)->get('/taskings/create?lga='.$this->world->lgas['B1']->ulid)->assertForbidden();
    expect(CrossFixtures::signIn($this->sd)->get('/lgas/'.$this->lgas['A1b']->ulid)->inertiaProps('can.sendTasking'))->toBeTrue();
    expect(CrossFixtures::signIn($this->world->users['lga_chief'])->get('/lgas/'.$this->lgas['A1a']->ulid)->inertiaProps('can.sendTasking'))->toBeFalse();
});

test('a vacant LGA can be sent to: the tasking waits for the next chief', function () {
    sendTasking([$this->lgas['A1a'], $this->lgas['A1c']])->assertSessionHasNoErrors();
    CrossFixtures::system();
    $tasking = Tasking::query()->sole();

    expect(TaskingRecipient::query()->where('tasking_id', $tasking->id)->count())->toBe(2);
    Notification::assertSentTo($this->chiefs['A1a'], TaskingNotification::class);
    Notification::assertNotSentTo($this->chiefs['A1b'], TaskingNotification::class);

    // The next chief of A1c sees it.
    $next = TaskingFixtures::chief($this->lgas['A1c']);
    CrossFixtures::signIn($next)->get("/taskings/{$tasking->ulid}")->assertOk();
});

test('a draft can be saved, edited and sent later; Send twice sends once', function () {
    CrossFixtures::signIn($this->sd)->post('/taskings', [
        'title' => 'Later', 'body' => '<p>Draft</p>', 'priority' => 'immediate', 'classification' => 'restricted', 'lgas' => [$this->lgas['A1b']->ulid],
    ])->assertRedirect();
    CrossFixtures::system();
    $draft = Tasking::query()->sole();
    expect($draft->draft_lga_ids)->toBe([$this->lgas['A1b']->id])
        ->and($draft->reference_no)->toBeNull();

    $edit = CrossFixtures::signIn($this->sd)->get("/taskings/{$draft->ulid}/edit")->assertOk()->inertiaProps();
    expect($edit['chosen'])->toBe([$this->lgas['A1b']->ulid]);

    $payload = ['send' => true, 'title' => 'Later', 'body' => '<p>Now</p>', 'priority' => 'immediate', 'classification' => 'restricted', 'lgas' => [$this->lgas['A1b']->ulid]];
    CrossFixtures::signIn($this->sd)->put("/taskings/{$draft->ulid}", $payload)->assertRedirect("/taskings/{$draft->ulid}");
    CrossFixtures::signIn($this->sd)->put("/taskings/{$draft->ulid}", $payload)->assertForbidden();
    CrossFixtures::system();

    expect($draft->fresh()?->status)->toBe(TaskingStatus::Sent)
        ->and(TaskingRecipient::query()->where('tasking_id', $draft->id)->count())->toBe(1);
});

test('the database refuses a sent tasking without recipients and recipients added after sending', function () {
    $draft = TaskingFixtures::draft($this->sd);

    expect(fn () => DB::transaction(fn () => DB::table('taskings')->where('id', $draft->id)->update([
        'status' => 'sent', 'reference_no' => 'TSK/X/2026/00009', 'sent_at' => now(), 'sent_by' => $this->sd->id, 'target_mode' => 'single', 'draft_lga_ids' => null,
    ])))->toThrow(QueryException::class, 'at least one LGA');

    $sent = TaskingFixtures::sent($this->sd, [$this->lgas['A1a']]);

    expect(fn () => DB::transaction(fn () => DB::table('tasking_recipients')->insert([
        'ulid' => (string) Str::ulid(), 'tasking_id' => $sent->id, 'state_id' => $this->lgas['A1b']->state_id, 'lga_id' => $this->lgas['A1b']->id,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'recipients are fixed');
});
