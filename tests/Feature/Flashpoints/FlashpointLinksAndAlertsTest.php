<?php

use App\Enums\Classification;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\FlashpointIncident;
use App\Models\IncidentType;
use App\Models\ThreatType;
use App\Models\User;
use App\Notifications\FlashpointNotification;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Database\Statistics;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
});

/** World users who received a FlashpointNotification for $event. */
function flashpointNotified(string $event, JurisdictionWorld $world): array
{
    $keys = [];

    foreach ($world->users as $key => $user) {
        if (Notification::sent($user, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === $event)->isNotEmpty()) {
            $keys[] = $key;
        }
    }
    sort($keys);

    return $keys;
}

/*
|--------------------------------------------------------------------------
| Links
|--------------------------------------------------------------------------
*/

test('a blackspot links past incidents, including a closed one; drafts and invalid incidents are refused', function () {
    $blackspot = F::flashpoint($this->lga, ['kind' => 'blackspot']);
    $verified = F::incident($this->lga, $this->chief);
    $resolved = F::incident($this->lga, $this->chief, 0.01, IncidentStatus::Resolved);
    $closed = F::incident($this->lga, $this->chief, 0.02, IncidentStatus::Closed);
    $draft = F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft);
    $invalid = F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Invalid);
    $url = "/flashpoints/{$blackspot->ulid}/incidents";

    CrossFixtures::signIn($this->chief);
    foreach ([$verified, $resolved, $closed] as $incident) {
        $this->post($url, ['record' => $incident->ulid, 'note' => 'Same gang'])->assertSessionHasNoErrors();
    }
    $this->post($url, ['record' => $draft->ulid])->assertSessionHasErrors('record');
    $this->post($url, ['record' => $invalid->ulid])->assertSessionHasErrors('record');
    // Twice is refused.
    $this->post($url, ['record' => $verified->ulid])->assertSessionHasErrors('record');

    CrossFixtures::system();
    expect(DB::table('flashpoint_incidents')->whereNull('deleted_at')->count())->toBe(3)
        ->and(DB::table('incidents')->where('id', $closed->id)->value('status'))->toBe('closed')
        ->and(Activity::query()->where('subject_id', $blackspot->id)->where('event', 'incident_linked')->count())->toBe(3);

    CrossFixtures::signIn($this->chief)->get("/flashpoints/{$blackspot->ulid}?tab=incidents")->assertInertia(fn (Assert $page) => $page
        ->has('incidents.linked', 3)
        ->where('incidents.linked.0.link.note', 'Same gang'));
});

test('unlinking is allowed (also for closed incidents) and logged', function () {
    $blackspot = F::flashpoint($this->lga);
    $closed = F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Closed);

    CrossFixtures::signIn($this->chief)->post("/flashpoints/{$blackspot->ulid}/incidents", ['record' => $closed->ulid])->assertSessionHasNoErrors();
    CrossFixtures::system();
    $link = FlashpointIncident::query()->sole();

    CrossFixtures::signIn($this->chief)->delete("/flashpoints/{$blackspot->ulid}/incidents/{$link->ulid}")->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect(FlashpointIncident::query()->count())->toBe(0)
        ->and(DB::table('flashpoint_incidents')->where('id', $link->id)->value('unlinked_by'))->toBe($this->chief->id)
        ->and(Activity::query()->where('event', 'incident_unlinked')->value('properties')['incident'] ?? null)->toBe($closed->ulid);

    // It can be linked again.
    CrossFixtures::signIn($this->chief)->post("/flashpoints/{$blackspot->ulid}/incidents", ['record' => $closed->ulid])->assertSessionHasNoErrors();
});

test('linking needs flashpoints.manage and sight of the incident; links show only when both sides are visible', function () {
    $flashpoint = F::flashpoint($this->lga);
    $secret = F::incident($this->lga, $this->sd, 0.0, IncidentStatus::Verified, ['classification' => Classification::Secret]);
    $open = F::incident($this->lga, $this->chief);

    // The chief cannot see the secret incident: it cannot be linked.
    CrossFixtures::signIn($this->chief)->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $secret->ulid])->assertSessionHasErrors('record');

    CrossFixtures::signIn($this->sd);
    $this->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $secret->ulid])->assertSessionHasNoErrors();
    $this->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $open->ulid])->assertSessionHasNoErrors();
    $this->get("/flashpoints/{$flashpoint->ulid}?tab=incidents")->assertInertia(fn (Assert $page) => $page->has('incidents.linked', 2));

    // The chief sees one link and no hint of the other.
    CrossFixtures::signIn($this->chief)->get("/flashpoints/{$flashpoint->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->has('incidents.linked', 1)->where('incidents.linked.0.ulid', $open->ulid));

    // A super admin cannot link anything.
    CrossFixtures::signIn($this->world->users['super_admin'])->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $open->ulid])->assertNotFound();
});

test('security notes link and unlink the same way, with suggestions from communities in the area', function () {
    $flashpoint = F::flashpoint($this->lga);
    $near = Community::factory()->in($this->lga)->at(F::LAT + 0.01, F::LNG)->create();
    $far = Community::factory()->in($this->lga)->at(F::LAT + 0.5, F::LNG)->create();
    $nearNote = CommunitySecurityNote::factory()->for($near)->create(['lga_id' => $this->lga->id, 'state_id' => $this->lga->state_id, 'title' => 'Near note']);
    CommunitySecurityNote::factory()->for($far)->create(['lga_id' => $this->lga->id, 'state_id' => $this->lga->state_id, 'title' => 'Far note', 'observed_at' => now()]);
    $otherLga = Community::factory()->in($this->world->lgas['A1b'])->at(F::LAT + 0.5, F::LNG)->create();
    CommunitySecurityNote::factory()->for($otherLga)->create(['lga_id' => $otherLga->lga_id, 'state_id' => $otherLga->state_id, 'title' => 'Other LGA note']);

    // The rest of the LGA is offered too, inside the area first; other LGAs only when inside the area.
    CrossFixtures::signIn($this->chief)->get("/flashpoints/{$flashpoint->ulid}?tab=security_notes")
        ->assertInertia(fn (Assert $page) => $page->has('securityNotes.suggestions', 2)
            ->where('securityNotes.suggestions.0.title', 'Near note')
            ->where('securityNotes.suggestions.1.title', 'Far note'));

    $this->post("/flashpoints/{$flashpoint->ulid}/security-notes", ['record' => $nearNote->ulid])->assertSessionHasNoErrors();
    $this->get("/flashpoints/{$flashpoint->ulid}?tab=security_notes")
        ->assertInertia(fn (Assert $page) => $page->has('securityNotes.linked', 1)->has('securityNotes.suggestions', 1));

    $link = DB::table('flashpoint_security_notes')->value('ulid');
    $this->delete("/flashpoints/{$flashpoint->ulid}/security-notes/{$link}")->assertSessionHasNoErrors();
    expect(DB::table('flashpoint_security_notes')->whereNull('deleted_at')->count())->toBe(0);
});

test('suggestions are visible incidents inside the radius in the last 12 months, not invalid or linked, optionally of the same category', function () {
    $threat = ThreatType::factory()->create(['category' => IncidentCategory::Crime]);
    $flashpoint = F::flashpoint($this->lga, ['threat_type_id' => $threat->id]);
    $conflict = IncidentType::factory()->create(['category' => IncidentCategory::ViolenceConflict]);

    $inside = F::incident($this->lga, $this->chief, 0.02, attributes: ['title' => 'Inside']);
    F::incident($this->lga, $this->chief, 0.2, attributes: ['title' => 'Outside the radius']);
    F::incident($this->lga, $this->chief, 0.0, attributes: ['title' => 'Too old', 'occurred_at' => CarbonImmutable::now()->subMonths(13)]);
    F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Invalid, ['title' => 'Invalid']);
    F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft, ['title' => 'Draft']);
    F::incident($this->lga, $this->chief, 0.01, attributes: ['title' => 'Other category', 'incident_type_id' => $conflict->id]);
    F::incident($this->lga, $this->sd, 0.01, attributes: ['title' => 'Secret', 'classification' => Classification::Secret]);
    $linked = F::incident($this->lga, $this->chief, 0.01, attributes: ['title' => 'Already linked']);

    CrossFixtures::signIn($this->chief)->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $linked->ulid])->assertSessionHasNoErrors();

    $titles = function (string $query) use ($flashpoint): array {
        $titles = [];
        $this->get("/flashpoints/{$flashpoint->ulid}?tab=incidents{$query}")->assertInertia(function (Assert $page) use (&$titles) {
            $titles = collect($page->toArray()['props']['incidents']['suggestions'])->pluck('title')->sort()->values()->all();
        });

        return $titles;
    };

    expect($titles(''))->toBe(['Inside', 'Other category'])
        ->and($titles('&months=24'))->toBe(['Inside', 'Other category', 'Too old'])
        ->and($titles('&same_category=1'))->toBe(['Inside'])
        ->and($inside->ulid)->toBeString();
});

/*
|--------------------------------------------------------------------------
| Incident submitted near a flashpoint
|--------------------------------------------------------------------------
*/

test('an incident submitted inside the radius notifies the SD, DSD and LGA chief once, and shows "Near flashpoint"', function () {
    $flashpoint = F::flashpoint($this->lga, ['kind' => 'blackspot', 'status' => 'active']);
    $incident = F::incident($this->lga, $this->chief, 0.02, IncidentStatus::Draft);

    W::submit($this->chief, $incident)->assertSessionHasNoErrors();

    expect(flashpointNotified('incident_nearby', $this->world))->toBe(['deputy_state_director', 'lga_chief', 'state_director']);
    Notification::assertSentTo($this->sd, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->flashpointUlid === $flashpoint->ulid
        && ! str_contains($n->body($this->sd), $flashpoint->name));

    // Returned and resubmitted: not again.
    W::step($this->sd, W::fresh($incident), 'return', ['reason' => 'Add the time'])->assertSessionHasNoErrors();
    W::submit($this->chief, W::fresh($incident))->assertSessionHasNoErrors();
    Notification::assertSentToTimes($this->sd, FlashpointNotification::class, 1);

    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('flashpoints.near.0.ulid', $flashpoint->ulid)
            ->where('flashpoints.near.0.can_link', true));

    // Linked from the incident page, it moves from "near" to "linked".
    $this->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $incident->ulid])->assertSessionHasNoErrors();
    $this->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('flashpoints.near', 0)->where('flashpoints.linked.0.ulid', $flashpoint->ulid));
});

test('assessments and links follow the scopes of their own place and of both sides', function () {
    $own = F::flashpoint($this->lga);
    $other = F::flashpoint($this->world->lgas['B1']);
    $secret = F::flashpoint($this->lga, [], Classification::Secret);
    $sdB = User::factory()->stateDirector($this->world->states['B1'])->create();

    foreach ([[$own, $this->sd], [$other, $sdB], [$secret, $this->sd]] as [$flashpoint, $by]) {
        CrossFixtures::signIn($by)->post("/flashpoints/{$flashpoint->ulid}/assessments", ['concern_level' => 'low', 'status' => 'active', 'assessment' => 'Noted.'])->assertSessionHasNoErrors();
        $this->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => F::incident($flashpoint->lga, $by)->ulid])->assertSessionHasNoErrors();
    }

    $context = app(JurisdictionContext::class);
    $counts = fn (User $u) => $context->actingAs($u, fn () => [
        FlashpointAssessment::query()->count(),
        FlashpointIncident::query()->count(),
    ]);

    expect($counts($this->chief))->toBe([1, 1])
        ->and($counts($this->sd))->toBe([2, 2])
        ->and($counts($sdB))->toBe([1, 1])
        ->and($counts($this->world->users['director_general']))->toBe([3, 3])
        ->and($counts($this->world->users['super_admin']))->toBe([0, 0]);
});

test('only owners who can see both the flashpoint and the incident are told; viewers who cannot see the flashpoint see nothing', function () {
    $secret = F::flashpoint($this->lga, [], Classification::Secret);
    $incident = F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft);

    W::submit($this->chief, $incident)->assertSessionHasNoErrors();

    // The chief and the deputy cannot see a secret flashpoint.
    expect(flashpointNotified('incident_nearby', $this->world))->toBe(['state_director']);

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('flashpoints.near', 0)->has('flashpoints.linked', 0));
    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('flashpoints.near.0.ulid', $secret->ulid));
});

test('dormant and resolved flashpoints, and incidents outside the radius, trigger nothing', function () {
    F::flashpoint($this->lga, ['status' => 'dormant']);
    F::flashpoint($this->lga, ['status' => 'resolved']);
    $active = F::flashpoint($this->lga, ['status' => 'monitoring', 'radius_meters' => 1000]);

    W::submit($this->chief, F::incident($this->lga, $this->chief, 0.05, IncidentStatus::Draft))->assertSessionHasNoErrors();
    W::submit($this->chief, F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft, ['location' => null]))->assertSessionHasNoErrors();

    Notification::assertNotSentTo($this->sd, FlashpointNotification::class);
    expect($active->ulid)->toBeString();
});

test('a blackspot with three incidents in 30 days asks for reassessment once per 30 days', function () {
    config(['buffalo.flashpoints.escalation_incident_count' => 3]);
    $blackspot = F::flashpoint($this->lga, ['kind' => 'blackspot']);
    $flashpoint = F::flashpoint($this->lga, ['kind' => 'flashpoint']);

    // One older incident linked (outside the area) and one nearby already.
    $linkedFar = F::incident($this->lga, $this->chief, 0.5);
    CrossFixtures::signIn($this->chief)->post("/flashpoints/{$blackspot->ulid}/incidents", ['record' => $linkedFar->ulid])->assertSessionHasNoErrors();
    F::incident($this->lga, $this->chief, 0.01);
    expect(flashpointNotified('reassessment_due', $this->world))->toBe([]);

    W::submit($this->chief, F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft))->assertSessionHasNoErrors();

    // The SD and DSD only.
    expect(flashpointNotified('reassessment_due', $this->world))->toBe(['deputy_state_director', 'state_director']);
    Notification::assertSentTo($this->sd, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === 'reassessment_due' && $n->flashpointUlid === $blackspot->ulid);
    Notification::assertNotSentTo($this->sd, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === 'reassessment_due' && $n->flashpointUlid === $flashpoint->ulid);

    // A fourth incident within the window: no second notice.
    W::submit($this->chief, F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft))->assertSessionHasNoErrors();
    expect(Notification::sent($this->sd, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === 'reassessment_due')->count())->toBe(1);

    // After the window, the next one notifies again.
    $this->travel(31)->days();
    foreach (range(1, 3) as $i) {
        W::submit($this->chief, F::incident($this->lga, $this->chief, 0.0, IncidentStatus::Draft, ['occurred_at' => CarbonImmutable::now()->subDay()]))->assertSessionHasNoErrors();
    }
    expect(Notification::sent($this->sd, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === 'reassessment_due')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Review reminders
|--------------------------------------------------------------------------
*/

test('due reviews are reminded once per due date to the LGA chief, SD and DSD who can see the flashpoint', function () {
    $due = F::flashpoint($this->lga, ['review_due_at' => CarbonImmutable::today()->subDay()]);
    $secret = F::flashpoint($this->lga, ['review_due_at' => CarbonImmutable::today()], Classification::Secret);
    F::flashpoint($this->lga, ['review_due_at' => CarbonImmutable::today()->addDay()]);
    F::flashpoint($this->lga, ['review_due_at' => CarbonImmutable::today()->subDay(), 'status' => 'resolved']);

    Artisan::call('buffalo:flashpoint-review-reminders');

    $for = fn (User $u, Flashpoint $f) => Notification::sent($u, FlashpointNotification::class, fn (FlashpointNotification $n) => $n->event === 'review_due' && $n->flashpointUlid === $f->ulid)->count();

    expect($for($this->chief, $due))->toBe(1)
        ->and($for($this->sd, $due))->toBe(1)
        ->and($for($this->world->users['deputy_state_director'], $due))->toBe(1)
        ->and($for($this->sd, $secret))->toBe(1)
        ->and($for($this->chief, $secret))->toBe(0)
        ->and(flashpointNotified('review_due', $this->world))->toBe(['deputy_state_director', 'lga_chief', 'state_director']);

    // Once per due date.
    Artisan::call('buffalo:flashpoint-review-reminders');
    expect($for($this->chief, $due))->toBe(1);

    // A new assessment sets a new date, reminded again when it comes.
    CrossFixtures::signIn($this->sd)->post("/flashpoints/{$due->ulid}/assessments", ['concern_level' => 'severe', 'status' => 'active', 'assessment' => 'Worse.'])->assertSessionHasNoErrors();
    $this->travel(31)->days();
    Artisan::call('buffalo:flashpoint-review-reminders');
    expect($for($this->chief, $due))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Index use
|--------------------------------------------------------------------------
*/

test('area matching uses ST_DWithin on the GIST index', function () {
    $incident = F::incident($this->lga, $this->chief);
    foreach (range(1, 30) as $i) {
        F::flashpoint($this->lga, ['name' => "Area {$i}"]);
    }
    Statistics::analyze('flashpoints');

    $plan = DB::transaction(function () use ($incident) {
        // The table is tiny in tests; make the planner show the index path it would use at scale.
        DB::statement('SET LOCAL enable_seqscan = off');

        return collect(DB::select(
            'EXPLAIN SELECT f.id FROM flashpoints f JOIN incidents i ON '.Flashpoint::containsSql('f', 'i.location')."
             WHERE i.id = ? AND f.deleted_at IS NULL AND f.status IN ('active', 'monitoring')",
            [$incident->id],
        ))->pluck('QUERY PLAN')->implode("\n");
    });

    expect($plan)->toContain('flashpoints_location_spatialindex')
        ->and($plan)->toContain('&&');
});
