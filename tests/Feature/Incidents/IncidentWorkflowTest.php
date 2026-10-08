<?php

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\IncidentCasualty;
use App\Models\IncidentStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->dg = $this->world->users['director_general'];
    $this->lga = $this->world->lgas['A1a'];
});

/*
|--------------------------------------------------------------------------
| Submission and reference numbers
|--------------------------------------------------------------------------
*/

test('submission needs only the core facts and assigns BUF/<state>/<lga>/<year>/<sequence>', function () {
    $incident = W::draft($this->lga, $this->chief);

    W::submit($this->chief, $incident)->assertSessionHasNoErrors()->assertRedirect("/incidents/{$incident->ulid}");

    $fresh = W::fresh($incident);
    $year = CarbonImmutable::now('Africa/Lagos')->year;
    expect($fresh->status)->toBe(IncidentStatus::Submitted)
        ->and($fresh->reference_no)->toBe(sprintf('BUF/%s/%s/%d/000001', $this->world->states['A1']->code, $this->lga->code, $year))
        ->and($fresh->submitted_by)->toBe($this->chief->id)
        ->and(IncidentStatusHistory::query()->where('incident_id', $incident->id)->sole()->to_status)->toBe(IncidentStatus::Submitted)
        ->and(Activity::query()->where('event', 'submitted')->where('subject_id', $incident->id)->exists())->toBeTrue();
});

test('a description under 20 characters cannot be submitted', function () {
    $incident = W::draft($this->lga, $this->chief, ['description' => 'Too short.']);

    W::submit($this->chief, $incident)->assertSessionHasErrors('description');
    expect(W::fresh($incident)->status)->toBe(IncidentStatus::Draft);
});

test('references are sequential per state per year, and stable on resubmission and on LGA change', function () {
    $first = W::draft($this->lga, $this->chief);
    $second = W::draft($this->world->lgas['A1b'], $this->sd);
    $directorA2 = User::factory()->stateDirector($this->world->states['A2'])->create();
    $otherState = W::draft($this->world->lgas['A2'], $directorA2);

    W::submit($this->chief, $first)->assertSessionHasNoErrors();
    W::submit($this->sd, $second)->assertSessionHasNoErrors();
    W::submit($directorA2, $otherState)->assertSessionHasNoErrors();

    $reference = W::fresh($first)->reference_no;
    expect($reference)->toEndWith('/000001')
        ->and(W::fresh($second)->reference_no)->toEndWith('/000002')
        ->and(W::fresh($otherState)->reference_no)->toEndWith('/000001');

    // Returned and resubmitted: same reference.
    W::step($this->sd, $first, 'return', ['reason' => 'Add the time of day.'])->assertSessionHasNoErrors();
    W::submit($this->chief, $first)->assertSessionHasNoErrors();
    expect(W::fresh($first)->reference_no)->toBe($reference);

    // The LGA corrected by the State Director: same reference.
    $payload = incidentWorkflowPayload(W::fresh($first), ['lga' => $this->world->lgas['A1b']->ulid]);
    CrossFixtures::signIn($this->sd)->put("/incidents/{$first->ulid}", $payload)->assertSessionHasNoErrors();
    expect(W::fresh($first)->reference_no)->toBe($reference)
        ->and(W::fresh($first)->lga_id)->toBe($this->world->lgas['A1b']->id)
        ->and(Activity::query()->where('event', 'lga_changed')->where('subject_id', $first->id)->exists())->toBeTrue();

    // A new year starts again.
    $this->travelTo(CarbonImmutable::create(CarbonImmutable::now()->year + 1, 1, 1, 0, 30, 0, 'Africa/Lagos'));
    $next = W::draft($this->lga, $this->chief, ['occurred_at' => now()->subHour()]);
    W::submit($this->chief, $next)->assertSessionHasNoErrors();
    expect(W::fresh($next)->reference_no)->toContain('/'.(CarbonImmutable::now('Africa/Lagos')->year).'/')->toEndWith('/000001');
});

test('reference numbers can be searched exactly and case-insensitively', function () {
    $incident = W::draft($this->lga, $this->chief);
    W::submit($this->chief, $incident);
    $reference = (string) W::fresh($incident)->reference_no;

    expect(Incident::query()->whereRaw('lower(reference_no) = lower(?)', [strtolower($reference)])->value('id'))->toBe($incident->id);
});

test('who may submit: the creator, the reporting officer, the SD/DSD of the state', function (string $who, bool $allowed) {
    $incident = W::draft($this->lga, $this->chief);
    $user = $who === 'other_chief' ? CrossFixtures::chiefOf($this->world->lgas['A1b']) : $this->world->users[$who];

    $response = W::submit($user, $incident);
    $allowed ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertStatus($who === 'other_chief' || $who === 'super_admin' || $who === 'ddg_zone_b' ? 404 : 403);
})->with([
    ['lga_chief', true],
    ['state_director', true],
    ['deputy_state_director', true],
    ['director_general', false],
    ['ddg_zone_b', false],
    ['super_admin', false],
    ['other_chief', false],
]);

/*
|--------------------------------------------------------------------------
| Transitions
|--------------------------------------------------------------------------
*/

/** A full payload for PUT /incidents/{ulid} from an incident. */
function incidentWorkflowPayload(Incident $incident, array $overrides = []): array
{
    CrossFixtures::system();
    $incident->load(['type', 'lga']);

    return [
        'incident_type' => $incident->type->ulid,
        'details' => [],
        'title' => $incident->title,
        'description' => $incident->description,
        'severity' => $incident->severity->value,
        'occurred_at' => $incident->occurred_at->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i'),
        'occurred_at_precision' => 'exact',
        'lga' => $incident->lga->ulid,
        'classification' => $incident->classification->value,
        'casualties' => [], 'persons' => [], 'groups' => [], 'agencies' => [], 'properties' => [],
        ...$overrides,
    ];
}

test('each transition: allowed roles, from the right status, with required fields', function (string $step, IncidentStatus $from, array $data, IncidentStatus $to, array $allowed, array $denied) {
    foreach ($allowed as $who) {
        $incident = W::at(W::draft($this->lga, $this->chief), $from);
        W::step($this->world->users[$who], $incident, $step, $data)->assertSessionHasNoErrors()->assertRedirect();
        expect(W::fresh($incident)->status)->toBe($to, "{$who} {$step}");
    }

    foreach ($denied as $who) {
        $incident = W::at(W::draft($this->lga, $this->chief), $from);
        W::step($this->world->users[$who], $incident, $step, $data)->assertForbidden();
        expect(W::fresh($incident)->status)->toBe($from, "{$who} {$step}");
    }
})->with([
    'return' => ['return', IncidentStatus::Submitted, ['reason' => 'Please add details.'], IncidentStatus::Returned, ['state_director', 'deputy_state_director'], ['lga_chief', 'director_general', 'ddg_national']],
    'verify' => ['verify', IncidentStatus::Submitted, [], IncidentStatus::Verified, ['state_director', 'deputy_state_director'], ['lga_chief', 'director_general']],
    'start investigation' => ['start-investigation', IncidentStatus::Verified, [], IncidentStatus::UnderInvestigation, ['state_director', 'deputy_state_director', 'director_general', 'ddg_national'], ['lga_chief']],
    'resolve' => ['resolve', IncidentStatus::UnderInvestigation, ['summary' => 'Victims released; suspects arrested.'], IncidentStatus::Resolved, ['state_director', 'deputy_state_director', 'director_general'], ['lga_chief']],
    'reopen' => ['reopen', IncidentStatus::Resolved, ['reason' => 'New evidence emerged.'], IncidentStatus::UnderInvestigation, ['state_director', 'director_general', 'ddg_national'], ['deputy_state_director', 'lga_chief']],
    'close' => ['close', IncidentStatus::Resolved, [], IncidentStatus::Closed, ['state_director', 'director_general', 'ddg_national'], ['deputy_state_director', 'lga_chief']],
    'invalidate' => ['invalidate', IncidentStatus::Submitted, ['reason' => 'false_report'], IncidentStatus::Invalid, ['state_director', 'director_general'], ['deputy_state_director', 'lga_chief']],
]);

test('transitions refuse the wrong status, missing fields and users out of jurisdiction', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified);

    W::step($this->sd, $incident, 'verify')->assertForbidden();
    W::step($this->sd, $incident, 'close')->assertForbidden();
    W::step($this->sd, $incident, 'resolve', ['summary' => ''])->assertSessionHasErrors('summary');
    W::step($this->sd, W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted), 'return', [])->assertSessionHasErrors('reason');
    W::step(User::factory()->stateDirector($this->world->states['B1'])->create(), $incident, 'start-investigation')->assertNotFound();
    W::step($this->world->users['ddg_zone_b'], $incident, 'start-investigation')->assertNotFound();
    W::step($this->world->users['ddg_zone_a'], $incident, 'start-investigation')->assertRedirect();
});

test('invalidating needs a note for "other" and a visible, valid original for a duplicate', function () {
    $original = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    $invalidOriginal = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Invalid, ['invalid_reason' => 'false_report']);
    $hidden = W::at(W::draft($this->world->lgas['B1'], User::factory()->directorGeneral()->create()), IncidentStatus::Submitted);
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);

    W::step($this->sd, $incident, 'invalidate', ['reason' => 'other'])->assertSessionHasErrors('note');
    W::step($this->sd, $incident, 'invalidate', ['reason' => 'duplicate'])->assertSessionHasErrors('duplicate_of');
    W::step($this->sd, $incident, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $hidden->ulid])->assertSessionHasErrors('duplicate_of');
    W::step($this->sd, $incident, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $invalidOriginal->ulid])->assertSessionHasErrors('duplicate_of');
    W::step($this->sd, $incident, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $incident->ulid])->assertSessionHasErrors('duplicate_of');
    W::step($this->sd, $incident, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $original->ulid])->assertSessionHasNoErrors();

    $fresh = W::fresh($incident);
    expect($fresh->status)->toBe(IncidentStatus::Invalid)
        ->and($fresh->duplicate_of_incident_id)->toBe($original->id);

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('incident.workflow.duplicate_of.ulid', $original->ulid)->where('incident.workflow.locked', true));
});

/*
|--------------------------------------------------------------------------
| Escalation
|--------------------------------------------------------------------------
*/

test('a critical incident is escalated automatically at submission', function () {
    $incident = W::draft($this->lga, $this->chief, ['severity' => Severity::Critical]);

    W::submit($this->chief, $incident)->assertSessionHasNoErrors();

    $fresh = W::fresh($incident);
    expect($fresh->isEscalated())->toBeTrue()
        ->and($fresh->escalation_reason)->toBe('Critical severity')
        ->and($fresh->escalated_by)->toBeNull();

    // Already escalated: cannot be escalated again.
    W::step($this->sd, $incident, 'escalate', ['reason' => 'Again'])->assertForbidden();
});

test('manual escalation by SD/DSD with a reason; acknowledgement by the DG or a covering DDG only', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);

    W::step($this->chief, $incident, 'escalate', ['reason' => 'Spreading'])->assertForbidden();
    W::step($this->dsd, $incident, 'escalate', ['reason' => ''])->assertSessionHasErrors('reason');
    W::step($this->dsd, $incident, 'escalate', ['reason' => 'Attackers moving towards the next LGA.'])->assertSessionHasNoErrors();
    expect(W::fresh($incident)->escalated_by)->toBe($this->dsd->id);

    W::step($this->sd, $incident, 'acknowledge')->assertForbidden();
    W::step($this->world->users['ddg_zone_b'], $incident, 'acknowledge')->assertNotFound();
    W::step($this->world->users['ddg_zone_a'], $incident, 'acknowledge')->assertSessionHasNoErrors();

    $fresh = W::fresh($incident);
    expect($fresh->escalation_acknowledged_by)->toBe($this->world->users['ddg_zone_a']->id)
        ->and($fresh->status)->toBe(IncidentStatus::Submitted);

    W::step($this->dg, $incident, 'acknowledge')->assertForbidden();
});

test('escalation is refused for drafts, resolved and closed incidents', function (IncidentStatus $status) {
    W::step($this->sd, W::at(W::draft($this->lga, $this->chief), $status), 'escalate', ['reason' => 'Serious'])->assertForbidden();
})->with([IncidentStatus::Draft, IncidentStatus::Returned, IncidentStatus::Resolved, IncidentStatus::Closed]);

/*
|--------------------------------------------------------------------------
| Editing after submission and locking
|--------------------------------------------------------------------------
*/

test('editing: the reporting side until verified; amend holders after, with a reason', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    $payload = incidentWorkflowPayload($incident, ['title' => 'Updated title']);

    CrossFixtures::signIn($this->chief)->put("/incidents/{$incident->ulid}", $payload)->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->dg)->put("/incidents/{$incident->ulid}", $payload)->assertForbidden();

    $verified = W::at($incident, IncidentStatus::Verified);
    CrossFixtures::signIn($this->chief)->put("/incidents/{$verified->ulid}", $payload)->assertForbidden();
    CrossFixtures::signIn($this->dsd)->put("/incidents/{$verified->ulid}", $payload)->assertSessionHasErrors('amendment_reason');
    CrossFixtures::signIn($this->dg)->put("/incidents/{$verified->ulid}", [...$payload, 'title' => 'Amended title', 'amendment_reason' => 'The title named the wrong road.'])->assertSessionHasNoErrors();

    expect(W::fresh($incident)->title)->toBe('Amended title')
        ->and(Activity::query()->where('event', 'amended')->where('subject_id', $incident->id)->sole()->properties['reason'])->toBe('The title named the wrong road.');
});

test('after submission only the SD and above may move the LGA; children follow', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    IncidentCasualty::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 3]);
    $payload = fn () => incidentWorkflowPayload(W::fresh($incident), [
        'lga' => $this->world->lgas['A1b']->ulid,
        'casualties' => [['ulid' => IncidentCasualty::query()->where('incident_id', $incident->id)->value('ulid'), 'outcome' => 'killed', 'group' => 'civilian', 'agency' => null, 'adult_male' => 5, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0, 'unknown' => 0]],
    ]);

    CrossFixtures::signIn($this->dsd)->put("/incidents/{$incident->ulid}", $payload())->assertForbidden();
    CrossFixtures::signIn($this->sd)->put("/incidents/{$incident->ulid}", $payload())->assertSessionHasNoErrors();

    CrossFixtures::system();
    $casualty = IncidentCasualty::query()->where('incident_id', $incident->id)->sole();
    expect($casualty->lga_id)->toBe($this->world->lgas['A1b']->id)
        ->and($casualty->adult_male)->toBe(5);

    // The history shows the figure revised from 3 to 5.
    $revision = Activity::query()->where('subject_type', $casualty->getMorphClass())->where('subject_id', $casualty->id)->where('event', 'updated')->latest('id')->first();
    expect($revision?->attribute_changes['old']['adult_male'])->toBe(3)
        ->and($revision?->attribute_changes['attributes']['adult_male'])->toBe(5);
});

test('closed and invalid incidents are locked: policies and database', function (IncidentStatus $status) {
    $incident = W::at(W::draft($this->lga, $this->chief), $status, $status === IncidentStatus::Invalid ? ['invalid_reason' => 'false_report'] : []);

    CrossFixtures::signIn($this->sd)->put("/incidents/{$incident->ulid}", incidentWorkflowPayload($incident, ['amendment_reason' => 'Trying to edit a locked incident.']))->assertForbidden();
    W::step($this->sd, $incident, 'updates', ['type' => 'follow_up', 'body' => 'Late note', 'classification' => 'restricted'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->postJson("/incidents/{$incident->ulid}/evidence", ['title' => 'x', 'classification' => 'restricted'])->assertForbidden();

    expect(fn () => DB::transaction(fn () => DB::table('incidents')->where('id', $incident->id)->update(['title' => 'Changed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('incident_casualties')->insert(['ulid' => (string) Str::ulid(), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 1, 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('incident_updates')->insert(['ulid' => (string) Str::ulid(), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'type' => 'follow_up', 'body' => 'x', 'classification' => 'restricted', 'created_by' => $this->sd->id, 'created_at' => now()])))->toThrow(QueryException::class);

    // A child row may not be moved OFF a locked incident either (found in Phase 10A).
    $open = W::draft($this->lga, $this->chief);
    $casualty = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    $id = DB::table('incident_casualties')->insertGetId(['ulid' => (string) Str::ulid(), 'incident_id' => $casualty->id, 'state_id' => $casualty->state_id, 'lga_id' => $casualty->lga_id, 'outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('incidents')->where('id', $casualty->id)->update(['status' => $status->value, ...($status === IncidentStatus::Invalid ? ['invalid_reason' => 'false_report'] : [])]);

    expect(fn () => DB::transaction(fn () => DB::table('incident_casualties')->where('id', $id)->update(['incident_id' => $open->id])))->toThrow(QueryException::class, 'locked');
})->with([IncidentStatus::Closed, IncidentStatus::Invalid]);

test('the status history is append-only', function () {
    $incident = W::draft($this->lga, $this->chief);
    W::submit($this->chief, $incident);
    $history = DB::table('incident_status_histories')->where('incident_id', $incident->id)->first();

    expect(fn () => DB::transaction(fn () => DB::table('incident_status_histories')->where('id', $history->id)->update(['comment' => 'rewritten'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('incident_status_histories')->where('id', $history->id)->delete()))->toThrow(QueryException::class);
});

test('the full path: submit, verify, investigate, resolve, close', function () {
    $incident = W::draft($this->lga, $this->chief);

    W::submit($this->chief, $incident)->assertSessionHasNoErrors();
    W::step($this->sd, $incident, 'verify', ['comment' => 'Confirmed by the divisional police.'])->assertSessionHasNoErrors();
    W::step($this->sd, $incident, 'start-investigation')->assertSessionHasNoErrors();
    W::step($this->sd, $incident, 'resolve', ['summary' => 'Both victims released; two arrests.'])->assertSessionHasNoErrors();
    W::step($this->sd, $incident, 'close')->assertSessionHasNoErrors();

    $fresh = W::fresh($incident);
    expect($fresh->status)->toBe(IncidentStatus::Closed)
        ->and($fresh->resolution_summary)->toBe('Both victims released; two arrests.')
        ->and(DB::table('incident_status_histories')->where('incident_id', $incident->id)->orderBy('id')->pluck('to_status')->all())
        ->toBe(['submitted', 'verified', 'under_investigation', 'resolved', 'closed']);

    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.addUpdate', false)->where('incident.workflow.locked', true));
});
