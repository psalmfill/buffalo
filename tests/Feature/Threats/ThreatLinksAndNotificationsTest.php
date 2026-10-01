<?php

use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Events\Threats\ThreatReportBroadcast;
use App\Events\Threats\ThreatReportChanged;
use App\Events\Threats\ThreatReportCreated;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\IncidentType;
use App\Models\ThreatReport;
use App\Models\ThreatReportIncident;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\ThreatDigest;
use App\Notifications\ThreatNotification;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->type = T::crimeType();
    $this->crime = IncidentType::factory()->create(['category' => IncidentCategory::Crime]);
});

const NATIONAL = ['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general'];

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

test('an immediate threat alerts the SD, DSD, LGA chief and national level (critical); payloads carry no content', function () {
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post('/threats', T::payload($this->lga, $this->type))->assertSessionHasNoErrors();

    expect(T::notified('created_immediate', $this->world))->toBe(collect(['lga_chief', 'state_director', ...NATIONAL])->sort()->values()->all());

    $threat = ThreatReport::query()->withoutGlobalScopes()->sole();
    Notification::assertSentTo($this->sd, ThreatNotification::class, function (ThreatNotification $n) use ($threat) {
        $payload = (string) json_encode($n->toArray($this->sd), JSON_UNESCAPED_SLASHES);

        return $n->level->value === 'critical'
            && str_contains($payload, $threat->reference_no)
            && str_contains($payload, 'Immediate')
            && ! str_contains($payload, 'Informants report')
            && ! str_contains($payload, 'cattle market')
            && ! str_contains($payload, 'Two independent informants');
    });
});

test('a priority threat alerts the SD, DSD and LGA chief; a routine one waits for the digest', function () {
    CrossFixtures::signIn($this->world->users['director_general'])->post('/threats', T::payload($this->lga, $this->type, ['urgency' => 'priority']))->assertSessionHasNoErrors();
    expect(T::notified('created_priority', $this->world))->toBe(['deputy_state_director', 'lga_chief', 'state_director']);
    Notification::assertSentTo($this->sd, ThreatNotification::class, fn (ThreatNotification $n) => $n->level->value === 'warning');

    Notification::fake();
    CrossFixtures::signIn($this->chief)->post('/threats', T::payload($this->lga, $this->type, ['urgency' => 'routine', 'title' => 'Routine one']))->assertSessionHasNoErrors();
    Notification::assertNothingSent();

    $this->artisan('buffalo:threat-digest')->assertSuccessful();
    Notification::assertSentTo($this->sd, ThreatDigest::class, fn (ThreatDigest $d) => $d->count === 1 && str_contains($d->body($this->sd), 'THR/'));
    Notification::assertSentTo($this->world->users['deputy_state_director'], ThreatDigest::class);
    Notification::assertNotSentTo($this->chief, ThreatDigest::class);
});

test('the digest lists only what each recipient can see', function () {
    T::threat($this->lga, ['urgency' => 'routine', 'reference_no' => 'THR/T/2026/R0001']);
    T::threat($this->lga, ['urgency' => 'routine', 'reference_no' => 'THR/T/2026/R0002'], Classification::Secret);
    T::threat($this->lga, ['urgency' => 'routine', 'reference_no' => 'THR/T/2026/R0003', 'created_at' => CarbonImmutable::now()->subDays(2)]);

    $this->artisan('buffalo:threat-digest')->assertSuccessful();

    Notification::assertSentTo($this->sd, ThreatDigest::class, fn (ThreatDigest $d) => $d->count === 2);
    Notification::assertSentTo($this->world->users['deputy_state_director'], ThreatDigest::class, fn (ThreatDigest $d) => $d->count === 1 && ! str_contains($d->body($this->sd), 'R0002'));
});

test('raising urgency to immediate or credibility to confirmed alerts the immediate set', function () {
    $threat = T::threat($this->lga, ['urgency' => 'priority', 'credibility' => 'possible', 'created_by' => $this->chief->id, 'threat_type_id' => $this->type->id]);
    $edit = fn (array $o) => [...T::payload($this->lga, $this->type, ['urgency' => 'priority', 'credibility' => 'possible']), 'change_comment' => 'New information', ...$o];

    CrossFixtures::signIn($this->chief)->put("/threats/{$threat->ulid}", $edit(['credibility' => 'probable']))->assertSessionHasNoErrors();
    Notification::assertNothingSent();

    CrossFixtures::signIn($this->chief)->put("/threats/{$threat->ulid}", $edit(['credibility' => 'confirmed', 'urgency' => 'priority']))->assertSessionHasNoErrors();
    expect(T::notified('escalated', $this->world))->toBe(collect(['deputy_state_director', 'state_director', ...NATIONAL])->sort()->values()->all());
});

test('materialised tells the creator and SD/DSD, and national level when the threat was immediate', function () {
    $routine = T::threat($this->lga, ['urgency' => 'priority', 'created_by' => $this->chief->id]);
    $immediate = T::threat($this->lga, ['urgency' => 'immediate', 'created_by' => $this->chief->id]);
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified);

    CrossFixtures::signIn($this->sd)->post("/threats/{$routine->ulid}/status", ['status' => 'materialised', 'note' => 'Happened', 'incident' => $incident->ulid])->assertSessionHasNoErrors();
    expect(T::notified('materialised', $this->world))->toBe(['deputy_state_director', 'lga_chief']);

    Notification::fake();
    CrossFixtures::signIn($this->sd)->post("/threats/{$immediate->ulid}/status", ['status' => 'materialised', 'note' => 'Happened', 'incident' => $incident->ulid])->assertSessionHasNoErrors();
    expect(T::notified('materialised', $this->world))->toBe(collect(['deputy_state_director', 'lga_chief', ...NATIONAL])->sort()->values()->all());
});

test('notifications reach only users who can see the threat report', function () {
    CrossFixtures::signIn($this->sd)->post('/threats', T::payload($this->lga, $this->type, ['classification' => 'secret']))->assertSessionHasNoErrors();

    // The chief and the deputy cannot see a secret report.
    expect(T::notified('created_immediate', $this->world))->toBe(NATIONAL);
});

/*
|--------------------------------------------------------------------------
| Real time
|--------------------------------------------------------------------------
*/

test('broadcasts carry only the ULID and event name, on the threat channels', function () {
    Event::fake([ThreatReportCreated::class, ThreatReportChanged::class]);

    CrossFixtures::signIn($this->chief)->post('/threats', T::payload($this->lga, $this->type))->assertSessionHasNoErrors();
    $threat = ThreatReport::query()->withoutGlobalScopes()->sole();
    CrossFixtures::signIn($this->chief)->post("/threats/{$threat->ulid}/status", ['status' => 'withdrawn', 'note' => 'Mistaken'])->assertSessionHasNoErrors();

    $check = function (ThreatReportBroadcast $event) use ($threat) {
        expect($event->broadcastWith())->toBe(['threat' => $threat->ulid, 'event' => $event->name()])
            ->and(array_map(fn ($c) => $c->name, $event->broadcastOn()))->toBe(["private-states.{$this->world->states['A1']->ulid}.threats", 'private-national.threats']);

        return true;
    };

    Event::assertDispatched(ThreatReportCreated::class, $check);
    Event::assertDispatched(ThreatReportChanged::class, $check);
});

test('threat channels: state channel for users covering the state, national for national users', function (string $who, bool $state, bool $national) {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');

    $authorize = fn (string $channel) => CrossFixtures::signIn($this->world->users[$who])->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->status();

    expect($authorize("private-states.{$this->world->states['A1']->ulid}.threats"))->toBe($state ? 200 : 403)
        ->and($authorize('private-national.threats'))->toBe($national ? 200 : 403);
})->with([
    ['lga_chief', true, false],
    ['state_director', true, false],
    ['director_general', true, true],
    ['ddg_zone_b', false, true],
    ['super_admin', false, false],
]);

/*
|--------------------------------------------------------------------------
| Links
|--------------------------------------------------------------------------
*/

test('links need sight of both sides; hidden links are neither shown nor counted', function () {
    $threat = T::threat($this->lga, ['created_by' => $this->chief->id]);
    $secretIncident = W::at(W::draft($this->lga, $this->sd, ['classification' => Classification::Secret]), IncidentStatus::Verified);
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Closed);
    $invalid = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Invalid);
    $flashpoint = Flashpoint::factory()->in($this->lga)->create();
    $group = CriminalGroup::factory()->in($this->lga)->create();
    $base = "/threats/{$threat->ulid}/links";

    CrossFixtures::signIn($this->chief);
    $this->post("{$base}/incidents", ['record' => $secretIncident->ulid, 'relation' => 'related'])->assertSessionHasErrors('record');
    $this->post("{$base}/incidents", ['record' => $invalid->ulid, 'relation' => 'related'])->assertSessionHasErrors('record');
    $this->post("{$base}/incidents", ['record' => $incident->ulid])->assertSessionHasErrors('relation');
    $this->post("{$base}/incidents", ['record' => $incident->ulid, 'relation' => 'related'])->assertSessionHasNoErrors();
    $this->post("{$base}/flashpoints", ['record' => $flashpoint->ulid])->assertSessionHasNoErrors();
    $this->post("{$base}/groups", ['record' => $group->ulid, 'role' => 'named_in_report'])->assertSessionHasNoErrors();
    $this->post("{$base}/groups", ['record' => $group->ulid, 'role' => 'named_in_report'])->assertSessionHasErrors('record');

    CrossFixtures::signIn($this->sd)->post("{$base}/incidents", ['record' => $secretIncident->ulid, 'relation' => 'materialised_as'])->assertSessionHasNoErrors();

    $counts = fn (User $u) => app(JurisdictionContext::class)->actingAs($u, fn () => ThreatReportIncident::query()->count());
    expect($counts($this->sd))->toBe(2)->and($counts($this->chief))->toBe(1);

    CrossFixtures::signIn($this->chief)->get("/threats/{$threat->ulid}?tab=links")->assertInertia(fn (Assert $page) => $page
        ->has('links.incidents', 1)
        ->where('links.incidents.0.ulid', $incident->ulid)
        ->where('links.groups.0.role.value', 'named_in_report')
        ->has('links.flashpoints', 1));

    // Unlink is logged; the link row stays (soft-deleted).
    $link = DB::table('threat_report_flashpoints')->value('ulid');
    $this->delete("{$base}/flashpoints/{$link}")->assertSessionHasNoErrors();
    expect(DB::table('threat_report_flashpoints')->whereNull('deleted_at')->count())->toBe(0)
        ->and(Activity::query()->where('subject_id', $threat->id)->where('event', 'flashpoint_unlinked')->exists())->toBeTrue();

    // The flashpoint page lists linked threats.
    CrossFixtures::signIn($this->chief)->post("{$base}/flashpoints", ['record' => $flashpoint->ulid])->assertSessionHasNoErrors();
    $this->get("/flashpoints/{$flashpoint->ulid}?tab=threats")->assertInertia(fn (Assert $page) => $page->where('threatReports.0.ulid', $threat->ulid));
});

test('initial flashpoint and group links can be given when recording', function () {
    $flashpoint = Flashpoint::factory()->in($this->lga)->create();
    $group = CriminalGroup::factory()->in($this->lga)->create();
    $hidden = Flashpoint::factory()->in($this->world->lgas['B1'])->create();

    CrossFixtures::signIn($this->chief)->post('/threats', T::payload($this->lga, $this->type, ['flashpoints' => [$hidden->ulid]]))->assertSessionHasErrors('flashpoints.0');
    CrossFixtures::signIn($this->chief)->post('/threats', T::payload($this->lga, $this->type, [
        'flashpoints' => [$flashpoint->ulid],
        'groups' => [['group' => $group->ulid, 'role' => 'suspected']],
    ]))->assertSessionHasNoErrors();

    expect(DB::table('threat_report_flashpoints')->count())->toBe(1)
        ->and(DB::table('threat_report_criminal_groups')->value('role'))->toBe('suspected');
});

/*
|--------------------------------------------------------------------------
| Incident submission hook
|--------------------------------------------------------------------------
*/

/** Submits an incident and returns the threat references matched to it. */
function matchedFor(JurisdictionWorld $world, array $attributes): array
{
    $incident = W::draft($world->lgas['A1a'], $world->users['lga_chief'], $attributes);
    W::submit($world->users['lga_chief'], $incident)->assertSessionHasNoErrors();

    return DB::table('threat_report_incident_matches')->join('threat_reports', 'threat_reports.id', '=', 'threat_report_incident_matches.threat_report_id')
        ->where('incident_id', $incident->id)->orderBy('reference_no')->pluck('reference_no')->all();
}

test('a submitted incident is matched by category, area and time window', function () {
    $point = fn (float $lat) => Flashpoint::pointFrom($lat, T::LNG);
    $community = Community::factory()->in($this->lga)->create();
    $conflict = ThreatType::factory()->create(['category' => IncidentCategory::ViolenceConflict]);
    $window = ['expected_from' => CarbonImmutable::now()->subDay(), 'expected_until' => CarbonImmutable::now()->addDay()];

    T::threat($this->lga, ['reference_no' => 'THR/T/2026/A-AREA', 'threat_type_id' => $this->type->id, 'location' => $point(T::LAT), 'radius_meters' => 2000, ...$window]);
    T::threat($this->lga, ['reference_no' => 'THR/T/2026/B-COMMUNITY', 'threat_type_id' => $this->type->id, 'community_id' => $community->id, ...$window]);
    T::threat($this->lga, ['reference_no' => 'THR/T/2026/C-LGA', 'threat_type_id' => $this->type->id, ...$window]);
    T::threat($this->lga, ['reference_no' => 'THR/T/2026/D-CATEGORY', 'threat_type_id' => $conflict->id, ...$window]);
    T::threat($this->lga, ['reference_no' => 'THR/T/2026/E-WITHDRAWN', 'threat_type_id' => $this->type->id, 'status' => 'withdrawn', 'status_note' => 'x', ...$window]);
    T::threat($this->world->lgas['A1b'], ['reference_no' => 'THR/T/2026/F-OTHER-LGA', 'threat_type_id' => $this->type->id, ...$window]);
    T::threat($this->lga, ['reference_no' => 'THR/T/2026/G-LATER', 'threat_type_id' => $this->type->id, 'expected_from' => CarbonImmutable::now()->addDays(5), 'expected_until' => CarbonImmutable::now()->addDays(6), 'expires_at' => CarbonImmutable::now()->addDays(6)]);

    $now = CarbonImmutable::now()->subHour();

    // Inside the point's radius, in the community, in the LGA.
    expect(matchedFor($this->world, ['incident_type_id' => $this->crime->id, 'occurred_at' => $now, 'location' => $point(T::LAT + 0.01), 'community_id' => $community->id]))
        ->toBe(['THR/T/2026/A-AREA', 'THR/T/2026/B-COMMUNITY', 'THR/T/2026/C-LGA']);

    // Outside the radius, no community: only the LGA-wide one.
    expect(matchedFor($this->world, ['incident_type_id' => $this->crime->id, 'occurred_at' => $now, 'location' => $point(T::LAT + 0.1)]))
        ->toBe(['THR/T/2026/C-LGA']);

    // Before the window opens but after the report was recorded: matches (before expiry).
    expect(matchedFor($this->world, ['incident_type_id' => $this->crime->id, 'occurred_at' => CarbonImmutable::now()->addMinutes(5)]))->toContain('THR/T/2026/G-LATER');

    // Before both the window and the report (old news): nothing.
    expect(matchedFor($this->world, ['incident_type_id' => $this->crime->id, 'occurred_at' => CarbonImmutable::now()->subDays(3)]))->toBe([]);
});

test('possibly related threats show only to viewers who can see them, with one-click materialise', function () {
    $window = ['expected_from' => CarbonImmutable::now()->subDay()];
    $threat = T::threat($this->lga, ['threat_type_id' => $this->type->id, 'created_by' => $this->chief->id, 'urgency' => 'immediate', ...$window]);
    $secret = T::threat($this->lga, ['threat_type_id' => $this->type->id, 'reference_no' => 'THR/T/2026/SECRET', ...$window], Classification::Secret);
    $incident = W::draft($this->lga, $this->chief, ['incident_type_id' => $this->crime->id, 'occurred_at' => CarbonImmutable::now()->subHour()]);
    W::submit($this->chief, $incident)->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('threats.possible', 1)
        ->where('threats.possible.0.ulid', $threat->ulid)
        ->where('threats.possible.0.can_materialise', true));
    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}")->assertInertia(fn (Assert $page) => $page->has('threats.possible', 2));

    // One click (with the prefilled note): linked as materialised, status set, national told.
    Notification::fake();
    CrossFixtures::signIn($this->chief)->post("/threats/{$threat->ulid}/status", ['status' => 'materialised', 'note' => 'Materialised as the incident.', 'incident' => $incident->ulid])->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('threats.possible', 0)
        ->where('threats.linked.0.relation.value', 'materialised_as')
        ->where('threats.linked.0.status.value', 'materialised'));
    expect(T::notified('materialised', $this->world))->toContain('director_general')
        ->and($secret->ulid)->toBeString();
});
