<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Models\RecordAccessLog;
use App\Models\ThreatReport;
use App\Models\User;
use App\Notifications\ThreatNotification;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\Threats\ThreatReferenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures;
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
});

/** Records a threat report over HTTP and returns it (system context). */
function recordThreat(User $user, array $payload): ThreatReport
{
    CrossFixtures::signIn($user)->post('/threats', $payload)->assertSessionHasNoErrors();
    CrossFixtures::system();

    return ThreatReport::query()->latest('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Reference numbers
|--------------------------------------------------------------------------
*/

test('references are THR/<state>/<year>/<5 digits>, sequential per state and year', function () {
    $year = CarbonImmutable::now()->setTimezone('Africa/Lagos')->year;
    $code = strtoupper($this->lga->state->code);

    $first = recordThreat($this->chief, T::payload($this->lga, $this->type));
    $second = recordThreat($this->sd, T::payload($this->world->lgas['A1b'], $this->type));
    $other = recordThreat($this->world->users['director_general'], T::payload($this->world->lgas['B1'], $this->type));

    expect($first->reference_no)->toBe("THR/{$code}/{$year}/00001")
        ->and($second->reference_no)->toBe("THR/{$code}/{$year}/00002")
        ->and($other->reference_no)->toBe('THR/'.strtoupper($this->world->states['B1']->code)."/{$year}/00001");

    // A failed creation rolls its number back.
    try {
        DB::transaction(function () {
            app(ThreatReferenceAllocator::class)->allocate($this->lga->state_id, CarbonImmutable::now());
            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
    }
    expect(recordThreat($this->chief, T::payload($this->lga, $this->type))->reference_no)->toBe("THR/{$code}/{$year}/00003");
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

test('each role may view, manage, reactivate and delete only as the matrix and jurisdiction allow', function () {
    $mine = T::threat($this->lga, ['created_by' => $this->chief->id]);
    $theirs = T::threat($this->lga, ['created_by' => $this->sd->id]);
    $abilities = fn (User $u, ThreatReport $t) => array_keys(array_filter([
        'view' => $u->can('view', $t),
        'update' => $u->can('update', $t),
        'reactivate' => $u->can('reactivate', $t),
        'delete' => $u->can('delete', $t),
    ]));
    $all = ['view', 'update', 'reactivate', 'delete'];

    $expected = [
        'super_admin' => [[], []],
        'director_general' => [$all, $all],
        'ddg_national' => [$all, $all],
        'ddg_zone_a' => [$all, $all],
        'ddg_zone_b' => [[], []],
        'state_director' => [$all, $all],
        'deputy_state_director' => [['view', 'update'], ['view', 'update']],
        // LGA chiefs manage only their own reports.
        'lga_chief' => [['view', 'update'], ['view']],
    ];

    foreach ($expected as $key => [$onMine, $onTheirs]) {
        expect($abilities($this->world->users[$key], $mine))->toBe($onMine, "{$key} on own")
            ->and($abilities($this->world->users[$key], $theirs))->toBe($onTheirs, "{$key} on another's");
    }

    expect($abilities(User::factory()->lgaChief($this->world->lgas['A1b'])->create(), $mine))->toBe([])
        ->and($abilities(User::factory()->stateDirector($this->world->states['B1'])->create(), $mine))->toBe([]);

    // Over HTTP too.
    CrossFixtures::signIn($this->chief)->put("/threats/{$theirs->ulid}", T::payload($this->lga, $this->type, ['credibility' => 'possible', 'urgency' => 'priority']))->assertForbidden();
});

test('threat reports outside the jurisdiction or above clearance are invisible; classified views are logged', function () {
    $own = T::threat($this->lga, ['title' => 'Own']);
    T::threat($this->world->lgas['A1b'], ['title' => 'Same state']);
    T::threat($this->world->lgas['B1'], ['title' => 'Other zone']);
    $secret = T::threat($this->lga, ['title' => 'Secret'], Classification::Secret);
    T::threat($this->lga, ['title' => 'Confidential'], Classification::Confidential);

    $titles = function (User $user, string $query = ''): array {
        $titles = [];
        CrossFixtures::signIn($user)->get("/threats{$query}")->assertOk()->assertInertia(function (Assert $page) use (&$titles) {
            $titles = collect($page->toArray()['props']['threats']['data'])->pluck('title')->sort()->values()->all();
        });

        return $titles;
    };

    expect($titles($this->chief))->toBe(['Own'])
        ->and($titles($this->world->users['deputy_state_director']))->toBe(['Confidential', 'Own', 'Same state'])
        ->and($titles($this->sd))->toBe(['Confidential', 'Own', 'Same state', 'Secret']);

    CrossFixtures::signIn($this->chief)->get("/threats/{$secret->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->sd)->get("/threats/{$secret->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/threats')->assertForbidden();

    expect(RecordAccessLog::query()->where('subject_type', $secret->getMorphClass())->where('subject_id', $secret->id)->exists())->toBeTrue()
        ->and(RecordAccessLog::query()->where('subject_type', $own->getMorphClass())->where('subject_id', $own->id)->exists())->toBeFalse();

    // The list shows active reports by default.
    T::threat($this->lga, ['title' => 'Withdrawn', 'status' => 'withdrawn', 'status_note' => 'Mistaken']);
    expect($titles($this->chief))->toBe(['Own'])
        ->and($titles($this->chief, '?status=any'))->toBe(['Own', 'Withdrawn']);

    // An LGA chief may not classify above their clearance.
    CrossFixtures::signIn($this->chief)->post('/threats', T::payload($this->lga, $this->type, ['classification' => 'secret']))->assertSessionHasErrors('classification');
});

test('the threat type in use is protected', function () {
    expect(app(ReferenceUsage::class)->isInUse($this->type))->toBeFalse();
    T::threat($this->lga, ['threat_type_id' => $this->type->id]);
    expect(app(ReferenceUsage::class)->isInUse($this->type))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

test('expiry defaults to the expected end, else 14 days, and is at most 90 days ahead', function () {
    $this->freezeTime();
    $until = CarbonImmutable::now()->addDays(3)->startOfMinute();

    $withWindow = recordThreat($this->chief, T::payload($this->lga, $this->type, ['expected_until' => $until->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i')]));
    expect($withWindow->expires_at->equalTo($until))->toBeTrue();

    $plain = recordThreat($this->chief, T::payload($this->lga, $this->type, ['expected_from' => null, 'expected_until' => null]));
    expect(abs($plain->expires_at->diffInSeconds(CarbonImmutable::now()->addDays(14))))->toBeLessThan(2);

    $local = fn (CarbonImmutable $t) => $t->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i');
    CrossFixtures::signIn($this->chief);
    $this->post('/threats', T::payload($this->lga, $this->type, ['expires_at' => $local(CarbonImmutable::now()->addDays(91))]))->assertSessionHasErrors(['expires_at' => 'The expiry can be at most 90 days ahead.']);
    $this->post('/threats', T::payload($this->lga, $this->type, ['expected_until' => null, 'expires_at' => $local(CarbonImmutable::now()->subHour())]))->assertSessionHasErrors(['expires_at' => 'The expiry must be in the future.']);
    $this->post('/threats', T::payload($this->lga, $this->type, ['expected_from' => null, 'expected_until' => $local(CarbonImmutable::now()->addDays(100))]))->assertSessionHasErrors('expires_at');
    $this->post('/threats', T::payload($this->lga, $this->type, ['expires_at' => $local(CarbonImmutable::now()->addDays(90))]))->assertSessionHasNoErrors();
});

test('the expiry job expires overdue reports (logged) and warns 24 hours ahead once', function () {
    $overdue = T::threat($this->lga, ['expires_at' => CarbonImmutable::now()->subMinute(), 'created_by' => $this->chief->id]);
    $soon = T::threat($this->lga, ['expires_at' => CarbonImmutable::now()->addHours(5), 'created_by' => $this->chief->id]);
    $later = T::threat($this->lga, ['expires_at' => CarbonImmutable::now()->addDays(3)]);

    $this->artisan('buffalo:expire-threat-reports')->assertSuccessful();

    expect($overdue->fresh()->status->value)->toBe('expired')
        ->and($overdue->fresh()->status_note)->toBe('Expired automatically.')
        ->and($soon->fresh()->status->value)->toBe('active')
        ->and($later->fresh()->status->value)->toBe('active')
        ->and(Activity::query()->where('subject_id', $overdue->id)->where('event', 'expired')->whereNull('causer_id')->exists())->toBeTrue()
        ->and(T::notified('expiring', $this->world))->toBe(['deputy_state_director', 'lga_chief', 'state_director']);

    // Once per expiry time.
    $this->artisan('buffalo:expire-threat-reports')->assertSuccessful();
    expect(Notification::sent($this->sd, ThreatNotification::class)->count())->toBe(1);
});

test('extending needs an active report, a comment and the 90-day maximum; reactivation is for SD and above', function () {
    $threat = T::threat($this->lga, ['created_by' => $this->chief->id, 'expires_at' => CarbonImmutable::now()->addHours(3)]);
    $local = fn (int $days) => CarbonImmutable::now()->addDays($days)->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i');

    CrossFixtures::signIn($this->chief);
    $this->post("/threats/{$threat->ulid}/extend", ['expires_at' => $local(5)])->assertSessionHasErrors('comment');
    $this->post("/threats/{$threat->ulid}/extend", ['expires_at' => $local(95), 'comment' => 'Still credible'])->assertSessionHasErrors('expires_at');
    $this->post("/threats/{$threat->ulid}/extend", ['expires_at' => $local(5), 'comment' => 'Still credible'])->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect($threat->fresh()->expires_at->greaterThan(CarbonImmutable::now()->addDays(4)))->toBeTrue()
        ->and(Activity::query()->where('subject_id', $threat->id)->where('event', 'extended')->value('properties')['comment'] ?? null)->toBe('Still credible');

    // Expire it, then reactivate.
    $this->travel(6)->days();
    $this->artisan('buffalo:expire-threat-reports');
    expect($threat->fresh()->status->value)->toBe('expired');

    CrossFixtures::signIn($this->chief)->post("/threats/{$threat->ulid}/extend", ['expires_at' => $local(5), 'comment' => 'x'])->assertSessionHasErrors('expires_at');
    CrossFixtures::signIn($this->chief)->post("/threats/{$threat->ulid}/reactivate", ['expires_at' => $local(5), 'comment' => 'x'])->assertForbidden();
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post("/threats/{$threat->ulid}/reactivate", ['expires_at' => $local(5), 'comment' => 'x'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->post("/threats/{$threat->ulid}/reactivate", ['expires_at' => $local(10), 'comment' => 'New reports'])->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect($threat->fresh()->status->value)->toBe('active')
        ->and(Activity::query()->where('subject_id', $threat->id)->where('event', 'reactivated')->exists())->toBeTrue();

    // A materialised report cannot be reactivated.
    $done = T::threat($this->lga, ['status' => 'materialised', 'status_note' => 'Happened']);
    CrossFixtures::signIn($this->sd)->post("/threats/{$done->ulid}/reactivate", ['expires_at' => $local(10), 'comment' => 'x'])->assertSessionHasErrors('expires_at');
});

/*
|--------------------------------------------------------------------------
| Assessment and status
|--------------------------------------------------------------------------
*/

test('credibility and urgency changes need a comment and are named events', function () {
    $threat = recordThreat($this->chief, T::payload($this->lga, $this->type, ['credibility' => 'possible', 'urgency' => 'priority']));
    $edit = T::payload($this->lga, $this->type, ['credibility' => 'probable', 'urgency' => 'priority']);
    unset($edit['expires_at']);

    CrossFixtures::signIn($this->chief);
    $this->put("/threats/{$threat->ulid}", $edit)->assertSessionHasErrors('change_comment');
    $this->put("/threats/{$threat->ulid}", [...$edit, 'change_comment' => 'Second source'])->assertSessionHasNoErrors();
    // Expiry is not editable here.
    $this->put("/threats/{$threat->ulid}", [...$edit, 'expires_at' => '2030-01-01T00:00'])->assertSessionHasErrors('expires_at');

    $event = Activity::query()->where('subject_id', $threat->id)->where('event', 'credibility_changed')->sole();
    expect($event->properties['old'])->toBe('possible')
        ->and($event->properties['new'])->toBe('probable')
        ->and($event->properties['comment'])->toBe('Second source')
        ->and(Activity::query()->where('subject_id', $threat->id)->where('event', 'urgency_changed')->exists())->toBeFalse();
});

test('leaving active needs a note; materialised needs a linked incident', function () {
    $threat = T::threat($this->lga, ['created_by' => $this->chief->id]);
    $incident = WorkflowFixtures::at(WorkflowFixtures::draft($this->lga, $this->chief), IncidentStatus::Verified);
    $url = "/threats/{$threat->ulid}/status";

    CrossFixtures::signIn($this->chief);
    $this->post($url, ['status' => 'mitigated'])->assertSessionHasErrors('note');
    $this->post($url, ['status' => 'expired', 'note' => 'x'])->assertSessionHasErrors('status');
    $this->post($url, ['status' => 'materialised', 'note' => 'It happened'])->assertSessionHasErrors('incident');
    $this->post($url, ['status' => 'materialised', 'note' => 'It happened', 'incident' => $incident->ulid])->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect($threat->fresh()->status->value)->toBe('materialised')
        ->and(DB::table('threat_report_incidents')->where('threat_report_id', $threat->id)->value('relation'))->toBe('materialised_as')
        ->and(Activity::query()->where('subject_id', $threat->id)->where('event', 'status_changed')->value('properties')['note'] ?? null)->toBe('It happened');

    // The database refuses a non-active status without a note.
    expect(fn () => DB::transaction(fn () => DB::table('threat_reports')->where('id', $threat->id)->update(['status_note' => null])))->toThrow(QueryException::class);

    // Only active reports change status.
    CrossFixtures::signIn($this->chief)->post($url, ['status' => 'withdrawn', 'note' => 'x'])->assertSessionHasErrors('status');
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

test('pages render for each tab, with ULIDs only', function () {
    $threat = recordThreat($this->chief, T::payload($this->lga, $this->type));

    CrossFixtures::signIn($this->chief);
    foreach (['overview', 'links', 'timeline', 'history'] as $tab) {
        $this->get("/threats/{$threat->ulid}?tab={$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('threats/show')
            ->where('tab', $tab)
            ->missing('threat.id')
            ->missing('threat.lga_id'));
    }
    $this->get("/threats/{$threat->ulid}?tab=timeline")->assertInertia(fn (Assert $page) => $page->where('timeline.0.event', 'created'));
    $this->get('/threats/create')->assertOk();
    $this->get("/threats/{$threat->ulid}/edit")->assertOk();
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('threatReports.active.0.ulid', $threat->ulid));
    CrossFixtures::signIn($this->sd)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('threatReports.byUrgency.0.total', 1));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('threatReports.immediate.0.ulid', $threat->ulid)
        ->where('threatReports.byStateAndType.0.total', 1));

    // The sidebar badge counts active immediate threats in reach.
    CrossFixtures::signIn($this->sd)->get('/threats')->assertInertia(fn (Assert $page) => $page->where('badges.immediateThreats', 1));
    CrossFixtures::signIn(User::factory()->stateDirector($this->world->states['B1'])->create())->get('/threats')->assertInertia(fn (Assert $page) => $page->where('badges.immediateThreats', 0));
});
