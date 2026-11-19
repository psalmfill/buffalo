<?php

use App\Actions\Taskings\SendTaskingOverdueAlerts;
use App\Enums\NotificationSeverity;
use App\Events\Taskings\TaskingChanged;
use App\Models\TaskingRecipient;
use App\Notifications\TaskingNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Taskings\TaskingFixtures;

/*
| Phase 11H.3 and the overdue rule (11H.2.6): who is told, at which
| severity, with which payload (reference, priority and link; never the
| body), and the live refresh events (ULID and event name only).
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->a = $this->world->lgas['A1a'];
    $this->b = $this->world->lgas['A1b'];
    $this->sd = TaskingFixtures::director($this->world->states['A1']);
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chiefA = TaskingFixtures::chief($this->a);
    $this->chiefB = TaskingFixtures::chief($this->b);
});

test('sent: each recipient LGA\'s chief, at the priority\'s severity, without the body', function (string $priority, NotificationSeverity $severity) {
    $tasking = TaskingFixtures::sent($this->sd, [$this->a], ['priority' => $priority, 'body' => '<p>Move the checkpoint to the bridge.</p>', 'title' => 'Bridge checkpoint']);

    Notification::assertSentTo($this->chiefA, TaskingNotification::class, function (TaskingNotification $n) use ($tasking, $severity) {
        $payload = $n->toArray($this->chiefA);

        return $n->event === 'sent'
            && $n->severity() === $severity
            && $payload['url'] === "/taskings/{$tasking->ulid}"
            && str_contains($payload['body'], (string) $tasking->reference_no)
            && ! str_contains(json_encode($payload), 'checkpoint')
            && ! str_contains(json_encode($payload), 'Bridge');
    });
    Notification::assertNotSentTo($this->chiefB, TaskingNotification::class);
})->with([
    ['routine', NotificationSeverity::Info],
    ['priority', NotificationSeverity::Warning],
    ['immediate', NotificationSeverity::Critical],
]);

test('follow-up notes and recalls go to each recipient LGA\'s chief', function () {
    $tasking = TaskingFixtures::sent($this->sd, [$this->a, $this->b]);
    Notification::fake();

    CrossFixtures::signIn($this->sd)->post("/taskings/{$tasking->ulid}/notes", ['body' => '<p>Note</p>']);
    foreach ([$this->chiefA, $this->chiefB] as $chief) {
        Notification::assertSentTo($chief, TaskingNotification::class, fn (TaskingNotification $n) => $n->event === 'note' && $n->severity() === NotificationSeverity::Info);
    }

    CrossFixtures::signIn($this->sd)->post("/taskings/{$tasking->ulid}/recall", ['reason' => 'Wrong roads named']);
    foreach ([$this->chiefA, $this->chiefB] as $chief) {
        Notification::assertSentTo($chief, TaskingNotification::class, fn (TaskingNotification $n) => $n->event === 'recalled'
            && $n->severity() === NotificationSeverity::Warning
            && ! str_contains(json_encode($n->toArray($chief)), 'Wrong roads'));
    }
});

test('acknowledged, completed and further responses go to the sender and the state\'s directors', function () {
    $tasking = TaskingFixtures::sent($this->sd, [$this->a]);
    CrossFixtures::system();
    $row = TaskingRecipient::query()->where('tasking_id', $tasking->id)->sole();
    Notification::fake();

    CrossFixtures::signIn($this->chiefA)->post("/tasking-recipients/{$row->ulid}/acknowledge");
    CrossFixtures::signIn($this->chiefA)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>Report back text</p>']);
    CrossFixtures::signIn($this->chiefA)->post("/tasking-recipients/{$row->ulid}/responses", ['body' => '<p>More</p>']);

    foreach (['acknowledged', 'completed', 'responded'] as $event) {
        foreach ([$this->sd, $this->dsd] as $director) {
            Notification::assertSentTo($director, TaskingNotification::class, fn (TaskingNotification $n) => $n->event === $event
                && $n->toArray($director)['url'] === "/taskings/{$tasking->ulid}?recipient={$row->ulid}"
                && ! str_contains(json_encode($n->toArray($director)), 'Report back text'));
        }
    }
    Notification::assertNotSentTo($this->chiefA, TaskingNotification::class);
});

test('live events carry only the ULID and event name, to the state channel and recipient chiefs', function () {
    Event::fake([TaskingChanged::class]);
    $tasking = TaskingFixtures::sent($this->sd, [$this->a]);

    Event::assertDispatched(TaskingChanged::class, function (TaskingChanged $e) use ($tasking) {
        $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

        return $e->broadcastWith() === ['tasking' => $tasking->ulid, 'event' => 'sent']
            && in_array('private-states.'.$this->world->states['A1']->ulid.'.taskings', $channels, true)
            && in_array('private-users.'.$this->chiefA->ulid, $channels, true)
            && ! in_array('private-users.'.$this->chiefB->ulid, $channels, true);
    });
});

test('the state tasking channel is for the state\'s directors and national users, never LGA chiefs', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
    $channel = 'private-states.'.$this->world->states['A1']->ulid.'.taskings';
    $auth = fn ($user) => CrossFixtures::signIn($user)->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->status();

    expect($auth($this->sd))->toBe(200)
        ->and($auth($this->world->users['director_general']))->toBe(200)
        ->and($auth($this->chiefA))->toBe(403)
        ->and($auth(CrossFixtures::directorOfB1($this->world)))->toBe(403);
});

test('overdue: the chief once at the due time, the sender once a day while any LGA is overdue', function () {
    config(['buffalo.taskings.overdue_summary_hour' => 8]);
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(10, 0));
    $tasking = TaskingFixtures::sent($this->sd, [$this->a, $this->b], ['response_due_at' => now()->addHour()]);
    CrossFixtures::system();
    $rowB = TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('lga_id', $this->b->id)->sole();
    CrossFixtures::signIn($this->chiefB)->post("/tasking-recipients/{$rowB->ulid}/complete", ['response' => '<p>Done</p>']);
    Notification::fake();
    $run = fn () => (function () {
        CrossFixtures::system();

        return app(SendTaskingOverdueAlerts::class)();
    })();

    // Not yet due.
    expect($run())->toBe([0, 0]);

    // Due: A1a's chief once; the sender's first summary at once (1 LGA overdue).
    $this->travel(2)->hours();
    expect($run())->toBe([1, 1])
        ->and($run())->toBe([0, 0]);
    Notification::assertSentToTimes($this->chiefA, TaskingNotification::class, 1);
    Notification::assertNotSentTo($this->chiefB, TaskingNotification::class);
    Notification::assertSentTo($this->sd, TaskingNotification::class, fn (TaskingNotification $n) => $n->event === 'overdue_summary' && $n->count === 1
        && $n->severity() === NotificationSeverity::Warning);

    // The next day, from the summary hour only.
    $this->travelTo(now()->setTimezone('Africa/Lagos')->addDay()->setTime(7, 0));
    expect($run())->toBe([0, 0]);
    $this->travelTo(now()->setTimezone('Africa/Lagos')->setTime(8, 5));
    expect($run())->toBe([0, 1])
        ->and($run())->toBe([0, 0]);

    // Completed: no more summaries.
    $rowA = TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('lga_id', $this->a->id)->sole();
    CrossFixtures::signIn($this->chiefA)->post("/tasking-recipients/{$rowA->ulid}/complete", ['response' => '<p>Late but done</p>']);
    $this->travelTo(now()->setTimezone('Africa/Lagos')->addDay()->setTime(9, 0));
    expect($run())->toBe([0, 0]);
});

test('recalled taskings are never overdue', function () {
    $tasking = TaskingFixtures::sent($this->sd, [$this->a], ['response_due_at' => now()->addHour()]);
    CrossFixtures::signIn($this->sd)->post("/taskings/{$tasking->ulid}/recall", ['reason' => 'No longer needed']);
    $this->travel(2)->hours();
    CrossFixtures::system();

    expect(app(SendTaskingOverdueAlerts::class)())->toBe([0, 0]);
    expect(CrossFixtures::signIn($this->sd)->get('/taskings')->inertiaProps('badges.taskings'))->toBe(0);
});

test('the directors\' badge counts taskings with an LGA overdue', function () {
    TaskingFixtures::sent($this->sd, [$this->a], ['response_due_at' => now()->addHour()]);
    expect(CrossFixtures::signIn($this->sd)->get('/taskings')->inertiaProps('badges.taskings'))->toBe(0);

    $this->travel(2)->hours();
    expect(CrossFixtures::signIn($this->sd)->get('/taskings')->inertiaProps('badges.taskings'))->toBe(1)
        ->and(CrossFixtures::signIn($this->world->users['director_general'])->get('/taskings')->inertiaProps('badges.taskings'))->toBe(0);
});

test('the overdue command is scheduled', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('buffalo:taskings:overdue')->assertSuccessful();
});
