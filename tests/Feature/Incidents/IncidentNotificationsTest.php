<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\NotificationSeverity;
use App\Enums\Severity;
use App\Events\Incidents\IncidentBroadcast;
use App\Events\Incidents\IncidentEscalated;
use App\Events\Incidents\IncidentStatusChanged;
use App\Events\Incidents\IncidentSubmitted;
use App\Events\Incidents\IncidentUpdated;
use App\Models\IncidentUpdate;
use App\Notifications\IncidentNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->dg = $this->world->users['director_general'];
    $this->lga = $this->world->lgas['A1a'];
});

/** Users who received an IncidentNotification for $event, by key in the world. */
function notifiedFor(string $event, JurisdictionWorld $world): array
{
    $keys = [];

    foreach ($world->users as $key => $user) {
        if (Notification::sent($user, IncidentNotification::class, fn (IncidentNotification $n) => $n->event === $event)->isNotEmpty()) {
            $keys[] = $key;
        }
    }
    sort($keys);

    return $keys;
}

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

test('submission notifies the SD and DSD of the state, with severity from the incident', function (Severity $severity, NotificationSeverity $expected) {
    W::submit($this->chief, W::draft($this->lga, $this->chief, ['severity' => $severity]))->assertSessionHasNoErrors();

    expect(notifiedFor('submitted', $this->world))->toBe(['deputy_state_director', 'state_director']);
    Notification::assertSentTo($this->sd, IncidentNotification::class, fn (IncidentNotification $n) => $n->event === 'submitted' && $n->level === $expected);
})->with([
    [Severity::Critical, NotificationSeverity::Critical],
    [Severity::High, NotificationSeverity::Warning],
    [Severity::Medium, NotificationSeverity::Info],
    [Severity::Low, NotificationSeverity::Info],
]);

test('a critical submission also reaches the DG and the DDGs covering the state, as critical', function () {
    W::submit($this->chief, W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]));

    expect(notifiedFor('submitted_critical', $this->world))->toBe(['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general']);
    Notification::assertSentTo($this->dg, IncidentNotification::class, fn (IncidentNotification $n) => $n->level === NotificationSeverity::Critical);
});

test('no one is notified about an incident they cannot see', function () {
    // Confidential: the DSD sees it, an LGA chief would not.
    $incident = W::draft($this->lga, $this->sd, ['classification' => Classification::Secret, 'severity' => Severity::Critical]);
    W::submit($this->sd, $incident)->assertSessionHasNoErrors();

    // Secret: the DSD cannot see it.
    expect(notifiedFor('submitted', $this->world))->toBe(['state_director'])
        ->and(notifiedFor('submitted_critical', $this->world))->toBe(['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general']);
});

test('returned, verified, resolved, closed and invalidated notify the reporting officer and creator', function (string $step, IncidentStatus $from, array $data, string $event) {
    $incident = W::at(W::draft($this->lga, $this->chief), $from);

    W::step($step === 'invalidate' || $step === 'close' ? $this->sd : $this->dsd, $incident, $step, $data)->assertSessionHasNoErrors();

    expect(notifiedFor($event, $this->world))->toBe(['lga_chief']);
})->with([
    ['return', IncidentStatus::Submitted, ['reason' => 'More detail'], 'returned'],
    ['verify', IncidentStatus::Submitted, [], 'verified'],
    ['resolve', IncidentStatus::Verified, ['summary' => 'Victims released safely.'], 'resolved'],
    ['close', IncidentStatus::Resolved, [], 'closed'],
    ['invalidate', IncidentStatus::Submitted, ['reason' => 'false_report'], 'invalidated'],
]);

test('escalation notifies national level; acknowledgement notifies the escalating officer', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);

    W::step($this->dsd, $incident, 'escalate', ['reason' => 'Spreading to the next LGA.']);
    expect(notifiedFor('escalated', $this->world))->toBe(['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general']);
    Notification::assertSentTo($this->dg, IncidentNotification::class, fn (IncidentNotification $n) => $n->event === 'escalated' && $n->level === NotificationSeverity::Warning);

    W::step($this->dg, $incident, 'acknowledge');
    expect(notifiedFor('acknowledged', $this->world))->toBe(['deputy_state_director']);
});

test('follow-ups notify the reporting officer and the SD/DSD but not the author; intelligence on escalated incidents reaches national level', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted, ['escalated_at' => now(), 'escalation_reason' => 'Critical severity']);

    W::step($this->chief, $incident, 'updates', ['type' => 'follow_up', 'body' => 'Second victim identified.', 'classification' => 'restricted'])->assertSessionHasNoErrors();
    expect(notifiedFor('update_added', $this->world))->toBe(['deputy_state_director', 'state_director'])
        ->and(notifiedFor('escalated_update', $this->world))->toBe([]);

    Notification::fake();
    W::step($this->sd, $incident, 'updates', ['type' => 'intelligence', 'body' => 'Suspects seen heading north.', 'classification' => 'secret'])->assertSessionHasNoErrors();
    // Secret: the chief and the DSD cannot see this follow-up.
    expect(notifiedFor('update_added', $this->world))->toBe([])
        ->and(notifiedFor('escalated_update', $this->world))->toBe(['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general']);
});

test('notification payloads carry the reference, type, LGA and link only', function () {
    $incident = W::draft($this->lga, $this->chief, ['title' => 'Musa Garba abducted', 'description' => 'Musa Garba, 34, was taken from his farm by gunmen.']);
    W::submit($this->chief, $incident);

    $notification = Notification::sent($this->sd, IncidentNotification::class)->first();
    $payload = (string) json_encode($notification->toArray($this->sd), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fresh = W::fresh($incident);

    expect($payload)->toContain((string) $fresh->reference_no)
        ->toContain($this->lga->name)
        ->toContain("/incidents/{$incident->ulid}")
        ->not->toContain('Musa')
        ->not->toContain('farm');
});

/*
|--------------------------------------------------------------------------
| Follow-ups and the timeline
|--------------------------------------------------------------------------
*/

test('follow-ups are append-only and never below the incident when written', function () {
    $incident = W::at(W::draft($this->lga, $this->chief, ['classification' => Classification::Confidential]), IncidentStatus::Submitted);

    W::step($this->sd, $incident, 'updates', ['type' => 'action_taken', 'body' => 'Roadblocks mounted.', 'classification' => 'restricted'])->assertSessionHasErrors('classification');
    W::step($this->chief, $incident, 'updates', ['type' => 'action_taken', 'body' => 'Roadblocks mounted.', 'classification' => 'secret'])->assertSessionHasErrors('classification');
    W::step($this->sd, $incident, 'updates', ['type' => 'action_taken', 'body' => 'Roadblocks mounted.', 'classification' => 'confidential'])->assertSessionHasNoErrors();

    $update = DB::table('incident_updates')->where('incident_id', $incident->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['body' => 'Rewritten'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('incident_updates')->where('id', $update->id)->delete()))->toThrow(QueryException::class);
});

test('follow-ups follow the incident when its LGA moves', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    W::step($this->chief, $incident, 'updates', ['type' => 'follow_up', 'body' => 'Note', 'classification' => 'restricted']);
    $b1 = $this->world->lgas['B1'];

    CrossFixtures::system();
    DB::table('incidents')->where('id', $incident->id)->update(['lga_id' => $b1->id, 'state_id' => $b1->state_id]);

    expect(DB::table('incident_updates')->where('incident_id', $incident->id)->value('lga_id'))->toBe($b1->id)
        ->and(DB::table('incident_status_histories')->where('incident_id', $incident->id)->count())->toBe(0);
});

test('the timeline merges status changes, follow-ups, escalation and evidence; items above clearance are absent', function () {
    $incident = W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]);
    W::submit($this->chief, $incident);
    W::step($this->dg, $incident, 'acknowledge');
    W::step($this->chief, $incident, 'updates', ['type' => 'follow_up', 'body' => 'Second victim identified.', 'classification' => 'restricted']);
    W::step($this->sd, $incident, 'updates', ['type' => 'intelligence', 'body' => 'Informant: hideout near the river.', 'classification' => 'secret']);

    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}?tab=timeline")
        ->assertInertia(fn (Assert $page) => $page
            ->where('timeline', fn ($entries) => collect($entries)->pluck('kind')->sort()->values()->all() === ['acknowledged', 'escalated', 'status', 'update_follow_up', 'update_intelligence']));

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}?tab=timeline")
        ->assertInertia(fn (Assert $page) => $page->where('timeline', fn ($entries) => ! collect($entries)->contains('kind', 'update_intelligence')))
        ->assertDontSee('hideout');

    // Reading the secret follow-up was logged for the SD.
    $secret = IncidentUpdate::query()->withoutGlobalScopes()->where('classification', 'secret')->sole();
    expect(DB::table('record_access_logs')->where('subject_type', $secret->getMorphClass())->where('subject_id', $secret->id)->where('user_id', $this->sd->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Real-time
|--------------------------------------------------------------------------
*/

test('broadcasts carry only the incident ULID and the event name, on the state and national channels', function () {
    Event::fake([IncidentSubmitted::class, IncidentStatusChanged::class, IncidentEscalated::class, IncidentUpdated::class]);
    $incident = W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]);

    W::submit($this->chief, $incident);
    W::step($this->sd, $incident, 'verify');
    W::step($this->chief, $incident, 'updates', ['type' => 'follow_up', 'body' => 'x', 'classification' => 'restricted']);

    $check = function (IncidentBroadcast $event) use ($incident): bool {
        expect($event->broadcastWith())->toBe(['incident' => $incident->ulid, 'event' => $event->name()])
            ->and(array_map(fn ($c) => $c->name, $event->broadcastOn()))->toBe(["private-states.{$this->world->states['A1']->ulid}.incidents", 'private-national.incidents']);

        return true;
    };

    Event::assertDispatched(IncidentSubmitted::class, $check);
    Event::assertDispatched(IncidentEscalated::class, $check);
    Event::assertDispatched(IncidentStatusChanged::class, $check);
    Event::assertDispatched(IncidentUpdated::class, $check);
});

test('channel authorisation: state channel for users covering the state, national channel for national users', function (string $who, bool $state, bool $national) {
    // As NotificationInfrastructureTest: the null test driver authorises
    // everything, so register the channels on a real Reverb driver.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    $user = $this->world->users[$who];
    $authorize = fn (string $channel) => CrossFixtures::signIn($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->status();

    expect($authorize("private-states.{$this->world->states['A1']->ulid}.incidents"))->toBe($state ? 200 : 403)
        ->and($authorize('private-national.incidents'))->toBe($national ? 200 : 403);
})->with([
    ['lga_chief', true, false],
    ['state_director', true, false],
    ['deputy_state_director', true, false],
    ['director_general', true, true],
    ['ddg_zone_a', true, true],
    ['ddg_zone_b', false, true],
    ['super_admin', false, false],
]);
