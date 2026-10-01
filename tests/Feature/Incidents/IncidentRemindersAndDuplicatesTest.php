<?php

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Community;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\IncidentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
});

/*
|--------------------------------------------------------------------------
| Verification deadlines
|--------------------------------------------------------------------------
*/

test('an unverified high incident: one reminder to the SD/DSD after 6 hours, national at 12, each once', function () {
    $incident = W::draft($this->lga, $this->chief, ['severity' => Severity::High]);
    W::submit($this->chief, $incident)->assertSessionHasNoErrors();
    Notification::fake();
    $reminders = fn (string $event) => collect($this->world->users)
        ->filter(fn (User $u) => Notification::sent($u, IncidentNotification::class, fn ($n) => $n->event === $event)->isNotEmpty())
        ->keys()->sort()->values()->all();

    $this->travel(5)->hours();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();
    expect($reminders('verification_overdue'))->toBe([]);

    $this->travel(61)->minutes();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();
    expect($reminders('verification_overdue'))->toBe(['deputy_state_director', 'state_director'])
        ->and(Notification::sent($this->sd, IncidentNotification::class)->count())->toBe(1)
        ->and($reminders('verification_overdue_national'))->toBe([]);

    $this->travel(6)->hours();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();
    // `locked_dg`'s lock has lapsed after 12 hours: it is a recipient again.
    expect(array_values(array_diff($reminders('verification_overdue_national'), ['locked_dg'])))->toBe(['ddg_national', 'ddg_zone_a', 'ddg_zones_a_b', 'director_general'])
        ->and(Notification::sent($this->world->users['director_general'], IncidentNotification::class)->count())->toBe(1);
});

test('verified incidents get no reminder; deadlines follow severity and config', function () {
    config(['buffalo.incidents.verify_within_minutes.critical' => 10]);
    $verified = W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]);
    W::submit($this->chief, $verified);
    W::step($this->sd, $verified, 'verify');
    $pending = W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]);
    W::submit($this->chief, $pending);
    Notification::fake();

    $this->travel(11)->minutes();
    $this->artisan('buffalo:incident-verification-reminders')->assertSuccessful();

    expect(DB::table('incident_reminders')->pluck('incident_id')->all())->toBe([$pending->id]);
});

/*
|--------------------------------------------------------------------------
| Duplicate detection
|--------------------------------------------------------------------------
*/

/** An incident at $place (community or coordinates), already submitted. */
function reported(array $attributes, JurisdictionWorld $world, string $lga = 'A1a'): Incident
{
    $incident = W::draft($world->lgas[$lga], $world->users['state_director'], $attributes);

    return W::at($incident, IncidentStatus::Submitted);
}

test('possible duplicates: same type, within 24 hours, same community or within 2 km; invalid excluded', function () {
    $community = Community::factory()->in($this->lga)->create();
    $base = ['occurred_at' => now()->subHours(3)];
    $first = reported([...$base, 'community_id' => $community->id], $this->world);
    $type = $first->incident_type_id;

    $near = reported(['incident_type_id' => $type, 'occurred_at' => now()->subHours(20), 'location' => Incident::pointFrom(6.4550, 3.3841)], $this->world);
    reported(['incident_type_id' => $type, 'occurred_at' => now()->subHours(2), 'location' => Incident::pointFrom(6.4900, 3.3841)], $this->world); // about 3.9 km
    reported(['incident_type_id' => $type, 'occurred_at' => now()->subHours(40), 'community_id' => $community->id], $this->world); // too early
    reported(['occurred_at' => now()->subHours(2), 'community_id' => $community->id], $this->world); // another type
    W::at(reported(['incident_type_id' => $type, 'occurred_at' => now()->subHours(2), 'community_id' => $community->id], $this->world), IncidentStatus::Invalid, ['invalid_reason' => 'false_report']);

    $new = W::draft($this->lga, $this->chief, [
        'incident_type_id' => $type,
        'occurred_at' => now()->subHours(1),
        'community_id' => $community->id,
        'location' => Incident::pointFrom(6.4560, 3.3850),
    ]);

    $found = CrossFixtures::signIn($this->chief)->getJson("/incidents/{$new->ulid}/duplicates")->assertOk()->json('duplicates');
    expect(array_column($found, 'ulid'))->toEqualCanonicalizing([$first->ulid, $near->ulid]);
});

test('duplicates are limited to incidents the submitter can see', function () {
    $community = Community::factory()->in($this->lga)->create();
    $hidden = reported(['occurred_at' => now()->subHours(2), 'community_id' => $community->id, 'classification' => 'secret'], $this->world);
    $new = W::draft($this->lga, $this->chief, ['incident_type_id' => $hidden->incident_type_id, 'occurred_at' => now()->subHour(), 'community_id' => $community->id]);

    expect(CrossFixtures::signIn($this->chief)->getJson("/incidents/{$new->ulid}/duplicates")->json('duplicates'))->toBe([]);
    expect(CrossFixtures::signIn($this->sd)->getJson("/incidents/{$new->ulid}/duplicates")->json('duplicates.0.ulid'))->toBe($hidden->ulid);
});

test('submitting with possible duplicates needs confirmation, which is recorded; the verifier sees them and can invalidate as duplicate', function () {
    $community = Community::factory()->in($this->lga)->create();
    $first = reported(['occurred_at' => now()->subHours(2), 'community_id' => $community->id], $this->world);
    $second = W::draft($this->lga, $this->chief, ['incident_type_id' => $first->incident_type_id, 'occurred_at' => now()->subHour(), 'community_id' => $community->id]);

    W::step($this->chief, $second, 'submit')->assertSessionHasErrors('duplicates');
    expect(W::fresh($second)->status)->toBe(IncidentStatus::Draft);

    W::step($this->chief, $second, 'submit', ['confirm_duplicates' => true])->assertSessionHasNoErrors();
    $event = Activity::query()->where('event', 'submitted')->where('subject_id', $second->id)->sole();
    expect($event->properties['possible_duplicates'][0]['incident'])->toBe($first->ulid);

    CrossFixtures::signIn($this->sd)->get("/incidents/{$second->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('possibleDuplicates.0.ulid', $first->ulid));
    // The chief (not a reviewer) is not shown them on the page.
    CrossFixtures::signIn($this->chief)->get("/incidents/{$second->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('possibleDuplicates', []));

    W::step($this->sd, $second, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $first->ulid])->assertSessionHasNoErrors();
    expect(W::fresh($second)->status)->toBe(IncidentStatus::Invalid);
});
