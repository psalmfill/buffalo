<?php

use App\Enums\TaskingRecipientStatus;
use App\Enums\TaskingStatus;
use App\Models\Lga;
use App\Models\Tasking;
use App\Models\TaskingNote;
use App\Models\TaskingRecipient;
use App\Models\TaskingResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Taskings\TaskingFixtures;

/*
| Phase 11H.2 and 11H.6: after sending. The tasking is fixed (HTTP and
| database); corrections are a recall with a reason or an append-only
| follow-up note. Each LGA reads, acknowledges, completes with a report
| back (required) and may add further responses; the directors see the
| progress counts.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->a = $this->world->lgas['A1a'];
    $this->b = $this->world->lgas['A1b'];
    $this->sd = $this->world->users['state_director'];
    $this->chief = TaskingFixtures::chief($this->a);
    $this->chiefB = TaskingFixtures::chief($this->b);
    $this->tasking = TaskingFixtures::sent($this->sd, [$this->a, $this->b], ['response_due_at' => now()->addDays(3)]);
    $this->row = fn (Lga $lga): TaskingRecipient => (function () use ($lga) {
        CrossFixtures::system();

        return TaskingRecipient::query()->where('tasking_id', $this->tasking->id)->where('lga_id', $lga->id)->sole();
    })();
});

test('a sent tasking cannot be edited over HTTP or in the database', function () {
    $t = $this->tasking;

    CrossFixtures::signIn($this->sd)->get("/taskings/{$t->ulid}/edit")->assertForbidden();
    CrossFixtures::signIn($this->sd)->put("/taskings/{$t->ulid}", [
        'title' => 'Changed', 'body' => '<p>Changed</p>', 'priority' => 'routine', 'classification' => 'restricted', 'lgas' => [$this->a->ulid],
    ])->assertForbidden();
    CrossFixtures::signIn($this->sd)->delete("/taskings/{$t->ulid}")->assertForbidden();

    foreach (['title' => 'Changed', 'body' => '<p>x</p>', 'priority' => 'immediate', 'classification' => 'confidential', 'response_due_at' => now()->addYear()] as $column => $value) {
        expect(fn () => DB::transaction(fn () => DB::table('taskings')->where('id', $t->id)->update([$column => $value])))
            ->toThrow(QueryException::class, 'a sent tasking cannot be changed');
    }

    expect(fn () => DB::transaction(fn () => DB::table('tasking_recipients')->where('tasking_id', $t->id)->delete()))
        ->toThrow(QueryException::class, 'never removed');
    CrossFixtures::system();
    expect($t->fresh()?->title)->toBe('Patrol the market roads');
});

test('recall needs a reason, which recipients see; nothing can be acted on afterwards', function () {
    $t = $this->tasking;
    CrossFixtures::signIn($this->sd)->post("/taskings/{$t->ulid}/recall", ['reason' => ''])->assertSessionHasErrors('reason');
    CrossFixtures::signIn($this->world->users['lga_chief'])->post("/taskings/{$t->ulid}/recall", ['reason' => 'Wrong roads named'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->post("/taskings/{$t->ulid}/recall", ['reason' => 'Wrong roads named'])->assertSessionHasNoErrors();
    CrossFixtures::system();

    expect($t->fresh()?->status)->toBe(TaskingStatus::Recalled);

    $props = CrossFixtures::signIn($this->chief)->get("/taskings/{$t->ulid}")->assertOk()->inertiaProps();
    expect($props['tasking']['recall'])->toMatchArray(['by' => $this->sd->name, 'reason' => 'Wrong roads named'])
        ->and($props['mine']['can']['respond'])->toBeFalse();

    $row = ($this->row)($this->a);
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/acknowledge")->assertForbidden();
    CrossFixtures::signIn($this->sd)->post("/taskings/{$t->ulid}/recall", ['reason' => 'Again and again'])->assertForbidden();

    // The database agrees.
    expect(fn () => DB::transaction(fn () => DB::table('tasking_recipients')->where('id', $row->id)->update([
        'status' => 'acknowledged', 'read_at' => now(), 'read_by' => $this->chief->id, 'acknowledged_at' => now(), 'acknowledged_by' => $this->chief->id,
    ])))->toThrow(QueryException::class, 'recalled');
});

test('follow-up notes are added by the directors, append-only, and seen by recipients', function () {
    $t = $this->tasking;
    CrossFixtures::signIn($this->chief)->post("/taskings/{$t->ulid}/notes", ['body' => '<p>Me too</p>'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->post("/taskings/{$t->ulid}/notes", ['body' => '<p> </p>'])->assertSessionHasErrors('body');
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post("/taskings/{$t->ulid}/notes", ['body' => '<p>Include the bridge.</p><script>x</script>'])
        ->assertSessionHasNoErrors();
    CrossFixtures::system();
    $note = TaskingNote::query()->sole();

    expect($note->body)->not->toContain('script');
    expect(CrossFixtures::signIn($this->chief)->get("/taskings/{$t->ulid}")->inertiaProps('notes.0.body'))->toContain('Include the bridge.');

    expect(fn () => DB::transaction(fn () => DB::table('tasking_notes')->where('id', $note->id)->update(['body' => '<p>Changed</p>'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tasking_notes')->where('id', $note->id)->delete()))->toThrow(QueryException::class);
});

test('opening marks the LGA read; acknowledge; complete needs a report back; further responses follow', function () {
    $t = $this->tasking;
    $row = ($this->row)($this->a);
    expect($row->status)->toBe(TaskingRecipientStatus::Delivered);

    // A director opening it does not mark anything read.
    CrossFixtures::signIn($this->sd)->get("/taskings/{$t->ulid}")->assertOk();
    expect(($this->row)($this->a)->status)->toBe(TaskingRecipientStatus::Delivered);

    CrossFixtures::signIn($this->chief)->get("/taskings/{$t->ulid}")->assertOk();
    $row = ($this->row)($this->a);
    expect($row->status)->toBe(TaskingRecipientStatus::Read)
        ->and($row->read_by)->toBe($this->chief->id);

    // Responses only after completing; completing needs text.
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/responses", ['body' => '<p>Early</p>'])->assertSessionHasErrors('body');
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/acknowledge")->assertSessionHasNoErrors();
    expect(($this->row)($this->a)->status)->toBe(TaskingRecipientStatus::Acknowledged);
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p></p>'])->assertSessionHasErrors('response');
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>Patrols doubled on both roads.</p>'])->assertSessionHasNoErrors();

    $row = ($this->row)($this->a);
    expect($row->status)->toBe(TaskingRecipientStatus::Completed)
        ->and($row->response_text)->toBe('Patrols doubled on both roads.');

    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>Again</p>'])->assertSessionHasErrors('response');
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/responses", ['body' => '<p>Two arrests since.</p>'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    $response = TaskingResponse::query()->sole();
    expect($response->lga_id)->toBe($this->a->id);

    // The completion report and responses are fixed.
    expect(fn () => DB::transaction(fn () => DB::table('tasking_recipients')->where('id', $row->id)->update(['response' => '<p>Rewritten</p>'])))
        ->toThrow(QueryException::class, 'cannot be changed');
    expect(fn () => DB::transaction(fn () => DB::table('tasking_recipients')->where('id', $row->id)->update(['status' => 'read'])))
        ->toThrow(QueryException::class, 'cannot move back');
    expect(fn () => DB::transaction(fn () => DB::table('tasking_responses')->where('id', $response->id)->update(['body' => '<p>x</p>'])))
        ->toThrow(QueryException::class);

    // The director sees the response.
    $selected = CrossFixtures::signIn($this->sd)->get("/taskings/{$t->ulid}?recipient={$row->ulid}")->assertOk()->inertiaProps('selected');
    expect($selected['response'])->toContain('Patrols doubled')
        ->and($selected['responses'][0]['body'])->toContain('Two arrests since.');
});

test('completing without acknowledging first acknowledges and reads it too', function () {
    $row = ($this->row)($this->b);
    CrossFixtures::signIn($this->chiefB)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>Done.</p>'])->assertSessionHasNoErrors();
    $row = ($this->row)($this->b);

    expect($row->status)->toBe(TaskingRecipientStatus::Completed)
        ->and($row->read_at)->not->toBeNull()
        ->and($row->acknowledged_at)->not->toBeNull();
});

test('only the recipient LGA\'s chief acts on its row', function () {
    $row = ($this->row)($this->a);

    // Another LGA's chief: the row is out of reach (404).
    CrossFixtures::signIn($this->chiefB)->post("/tasking-recipients/{$row->ulid}/acknowledge")->assertNotFound();

    foreach (['state_director', 'deputy_state_director', 'director_general'] as $role) {
        CrossFixtures::signIn($this->world->users[$role])->post("/tasking-recipients/{$row->ulid}/acknowledge")->assertForbidden();
        CrossFixtures::signIn($this->world->users[$role])->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>x</p>'])->assertForbidden();
    }
});

test('directors see progress counts: read, acknowledged, completed and overdue', function () {
    $t = $this->tasking;
    $rowA = ($this->row)($this->a);
    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$rowA->ulid}/complete", ['response' => '<p>Done.</p>']);
    CrossFixtures::signIn($this->chiefB)->get("/taskings/{$t->ulid}");

    $progress = CrossFixtures::signIn($this->sd)->get("/taskings/{$t->ulid}")->inertiaProps('progress');
    expect($progress)->toMatchArray(['total' => 2, 'read' => 2, 'acknowledged' => 1, 'completed' => 1, 'overdue' => 0])
        ->and($progress['line'])->toBe('2 of 2 read · 1 acknowledged · 1 completed');

    $this->travel(4)->days();
    $progress = CrossFixtures::signIn($this->sd)->get("/taskings/{$t->ulid}")->inertiaProps('progress');
    expect($progress['overdue'])->toBe(1)
        ->and($progress['line'])->toEndWith('· 1 overdue');

    // The dashboard card shows the same line.
    $card = CrossFixtures::signIn($this->sd)->get('/dashboard')->inertiaProps('taskings');
    expect($card['latest'][0]['progress']['line'])->toBe($progress['line']);
});

test('drafts are seen and discarded only by the state\'s directors', function () {
    $draft = TaskingFixtures::draft($this->sd);

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/taskings/{$draft->ulid}/edit")->assertOk();
    CrossFixtures::signIn($this->world->users['director_general'])->get("/taskings/{$draft->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/taskings/{$draft->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->sd)->delete("/taskings/{$draft->ulid}")->assertRedirect();
    CrossFixtures::system();

    expect(Tasking::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(Tasking::withTrashed()->whereKey($draft->id)->exists())->toBeTrue();
});
