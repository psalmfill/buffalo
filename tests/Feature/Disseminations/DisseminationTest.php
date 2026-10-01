<?php

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\DisseminationTargetLevel;
use App\Enums\ThreatUrgency;
use App\Events\DisseminationEnded;
use App\Models\DisseminationRequest;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\RecordAccessLog;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\ThreatReportIncident;
use App\Models\User;
use App\Notifications\DisseminationNotification;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationResolver;
use App\Support\Jurisdiction\DisseminationTargets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Owner: state A1, LGA A1a (its SD, DSD and chief are the world's users).
| Recipients created here: SD/DSD/chief of A2 (another state), chief of A1b
| (a neighbouring LGA in the same state), SD of B1 (never targeted).
*/
beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->owner = $this->world->users['state_director'];
    $this->ownerDeputy = $this->world->users['deputy_state_director'];
    $this->ownerChief = $this->world->users['lga_chief'];
    $this->a2 = $this->world->states['A2'];
    $this->a2Lga = $this->world->lgas['A2'];
    $this->a2Director = User::factory()->stateDirector($this->a2)->create();
    $this->a2Deputy = User::factory()->deputyStateDirector($this->a2)->create();
    $this->a2Chief = CrossFixtures::chiefOf($this->a2Lga);
    $this->neighbourChief = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    $this->b1Director = CrossFixtures::directorOfB1($this->world);
    $this->threat = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate, 'created_by' => $this->owner->id]);
});

/** @param list<array{0: string, 1: State|Lga}> $targets */
function disseminate(User $actor, Disseminable $subject, array $targets, string $reason = 'Armed men expected to cross into your area this week.')
{
    $path = $subject instanceof ThreatReport ? "/threats/{$subject->ulid}/disseminations" : "/flashpoints/{$subject->ulid}/disseminations";

    return CrossFixtures::signIn($actor)->post($path, [
        'targets' => array_map(fn (array $t) => ['level' => $t[0], 'ulid' => $t[1]->ulid], $targets),
        'reason' => $reason,
    ]);
}

function showThreat(User $user, ThreatReport $threat)
{
    return CrossFixtures::signIn($user)->get("/threats/{$threat->ulid}");
}

function onlyRow(): RecordDissemination
{
    return RecordDissemination::query()->latest('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
*/

test('an LGA-level dissemination reaches that LGA\'s chief and its state\'s SD and DSD only', function () {
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]])->assertSessionHasNoErrors();

    foreach ([$this->a2Chief, $this->a2Director, $this->a2Deputy] as $user) {
        showThreat($user, $this->threat)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dissemination.target', fn ($t) => str_contains((string) $t, $this->a2Lga->name))
            ->where('sharing', null)
            ->where('can.update', false));
    }

    showThreat($this->b1Director, $this->threat)->assertNotFound();
    showThreat($this->neighbourChief, $this->threat)->assertNotFound();
});

test('a state-level dissemination reaches the target SD and DSD, not its LGA chiefs', function () {
    disseminate($this->owner, $this->threat, [['state', $this->a2]])->assertSessionHasNoErrors();

    showThreat($this->a2Director, $this->threat)->assertOk();
    showThreat($this->a2Deputy, $this->threat)->assertOk();
    showThreat($this->a2Chief, $this->threat)->assertNotFound();
    showThreat($this->b1Director, $this->threat)->assertNotFound();
});

test('a neighbouring LGA in the same state is a valid target; the owning state is not', function () {
    disseminate($this->owner, $this->threat, [['lga', $this->world->lgas['A1b']]])->assertSessionHasNoErrors();
    showThreat($this->neighbourChief, $this->threat)->assertOk()->assertInertia(fn (Assert $page) => $page->has('dissemination.ulid'));

    // The owning SD is not a recipient: no banner, the owners' panel instead,
    // nothing in their inbox or badge.
    showThreat($this->owner, $this->threat)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dissemination', null)
        ->has('sharing.disseminations', 1)
        ->where('badges.disseminations', 0));
    CrossFixtures::signIn($this->owner)->get('/disseminations')->assertInertia(fn (Assert $page) => $page->has('received', 0));

    disseminate($this->owner, $this->threat, [['state', $this->world->states['A1']]])->assertSessionHasErrors('targets');
    disseminate($this->owner, $this->threat, [['lga', $this->world->lgas['A1a']]])->assertSessionHasErrors('targets');
    disseminate($this->owner, $this->threat, [['lga', $this->world->lgas['A1b']]])->assertSessionHasErrors('targets');
    expect(RecordDissemination::query()->count())->toBe(1);
});

test('lists and the map include shared records with a marker, and filter on them', function () {
    T::threat($this->a2Lga, ['title' => 'A2 own threat']);
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);

    CrossFixtures::signIn($this->a2Chief)->get('/threats')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('threats.data', 2)
        ->where('threats.data', fn ($rows) => collect($rows)->firstWhere('ulid', $this->threat->ulid)['shared'] === true
            && collect($rows)->firstWhere('title', 'A2 own threat')['shared'] === false));

    CrossFixtures::signIn($this->a2Chief)->get('/threats?shared=1')->assertInertia(fn (Assert $page) => $page
        ->has('threats.data', 1)
        ->where('threats.data.0.ulid', $this->threat->ulid));

    $threat = ThreatReport::query()->withoutGlobalScopes()->whereKey($this->threat->id)->sole();
    DB::table('threat_reports')->where('id', $threat->id)->update(['location' => DB::raw('ST_SetSRID(ST_MakePoint(3.4, 6.5), 4326)::geography'), 'radius_meters' => 2000]);
    $features = CrossFixtures::signIn($this->a2Chief)->getJson('/map/layers/threats?bbox=3,6,4,7&zoom=10')->assertOk()->json('features');
    expect(collect($features)->firstWhere('properties.ulid', $this->threat->ulid)['properties']['shared'])->toBeTrue();
});

test('expiry and revocation remove access at once and broadcast the end', function () {
    Event::fake([DisseminationEnded::class]);
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);
    $row = onlyRow();

    CrossFixtures::signIn($this->owner)->post("/disseminations/{$row->ulid}/revoke", ['note' => 'Threat contained.'])->assertSessionHasNoErrors();
    showThreat($this->a2Chief, $this->threat)->assertNotFound();
    Event::assertDispatched(DisseminationEnded::class, fn (DisseminationEnded $e) => $e->holderUlid === $this->a2Chief->ulid && $e->disseminationUlid === $row->ulid);
    expect(Activity::query()->where('event', 'revoked')->where('subject_id', $row->id)->exists())->toBeTrue();

    // Expiry (a flashpoint, 90 days).
    $flashpoint = F::flashpoint($this->world->lgas['A1a']);
    disseminate($this->owner, $flashpoint, [['lga', $this->a2Lga]])->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->a2Chief)->get("/flashpoints/{$flashpoint->ulid}")->assertOk();

    $this->travel(91)->days();
    CrossFixtures::signIn($this->a2Chief)->get("/flashpoints/{$flashpoint->ulid}")->assertNotFound();
    $this->artisan('buffalo:process-disseminations')->assertSuccessful();
    Event::assertDispatched(DisseminationEnded::class, fn (DisseminationEnded $e) => $e->disseminationUlid === onlyRow()->ulid);
    Notification::assertSentTo($this->a2Chief, DisseminationNotification::class, fn (DisseminationNotification $n) => $n->event === 'expired');
    expect(Activity::query()->where('event', 'expired')->count())->toBe(1);

    // Renewing brings it back.
    CrossFixtures::signIn($this->owner)->post('/disseminations/'.onlyRow()->ulid.'/renew')->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->a2Chief)->get("/flashpoints/{$flashpoint->ulid}")->assertOk();
});

/*
|--------------------------------------------------------------------------
| Classification ceiling
|--------------------------------------------------------------------------
*/

test('a secret threat disseminated to an LGA stays invisible to its chief and deputy; the sender is warned', function () {
    $secret = T::threat($this->world->lgas['A1a'], ['created_by' => $this->owner->id], Classification::Secret);

    showThreat($this->owner, $secret)->assertInertia(fn (Assert $page) => $page
        ->where('sharing.audience.hidden.lga', ['LGA Security Chief', 'Deputy State Director'])
        ->where('sharing.audience.lga', ['State Director']));

    disseminate($this->owner, $secret, [['lga', $this->a2Lga]])->assertSessionHasNoErrors();

    showThreat($this->a2Chief, $secret)->assertNotFound();
    showThreat($this->a2Deputy, $secret)->assertNotFound();
    showThreat($this->a2Director, $secret)->assertOk();
    CrossFixtures::signIn($this->a2Chief)->get('/disseminations')->assertInertia(fn (Assert $page) => $page->has('received', 0));
    Notification::assertNotSentTo($this->a2Chief, DisseminationNotification::class);
    Notification::assertNotSentTo($this->a2Deputy, DisseminationNotification::class);
    Notification::assertSentTo($this->a2Director, DisseminationNotification::class);
});

/*
|--------------------------------------------------------------------------
| Read-only
|--------------------------------------------------------------------------
*/

test('recipients can view and acknowledge, nothing else', function () {
    $flashpoint = F::flashpoint($this->world->lgas['A1a']);
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);
    disseminate($this->owner, $flashpoint, [['lga', $this->a2Lga]]);

    foreach ([$this->a2Chief, $this->a2Director] as $user) {
        $as = fn () => CrossFixtures::signIn($user);
        $t = "/threats/{$this->threat->ulid}";
        $f = "/flashpoints/{$flashpoint->ulid}";

        $as()->get("{$t}/edit")->assertForbidden();
        $as()->put($t, T::payload($this->world->lgas['A1a'], $this->threat->threatType, ['credibility' => 'possible', 'comment' => 'Edited by a recipient.']))->assertForbidden();
        $as()->post("{$t}/status", ['status' => 'mitigated', 'note' => 'Handled by us.'])->assertForbidden();
        $as()->post("{$t}/extend", ['expires_at' => now()->addDays(5)->format('Y-m-d\TH:i'), 'comment' => 'Longer.'])->assertForbidden();
        $as()->delete($t, ['reason' => 'Not needed any more.'])->assertForbidden();
        $as()->post("{$t}/links/flashpoints", ['record' => $flashpoint->ulid])->assertForbidden();
        $as()->post("{$t}/disseminations", ['targets' => [['level' => 'state', 'ulid' => $this->world->states['B1']->ulid]], 'reason' => str_repeat('x', 25)])->assertForbidden();
        $as()->get("{$f}/edit")->assertForbidden();
        $as()->put($f, collect(F::payload($this->world->lgas['A1a'], $flashpoint->threatType))->except(['concern_level', 'status', 'assessment', 'recommended_action'])->all())->assertForbidden();
        $as()->post("{$f}/assessments", ['concern_level' => 'low', 'status' => 'dormant', 'assessment' => 'Calm now.'])->assertForbidden();
        $as()->delete($f, ['reason' => 'Not needed any more.'])->assertForbidden();
        $as()->post("{$f}/disseminations", ['targets' => [['level' => 'state', 'ulid' => $this->world->states['B1']->ulid]], 'reason' => str_repeat('x', 25)])->assertForbidden();
    }

    $row = RecordDissemination::query()->where('subject_type', 'threat_report')->sole();
    CrossFixtures::signIn($this->a2Chief)->post("/disseminations/{$row->ulid}/acknowledge")->assertSessionHasNoErrors();
    expect($row->refresh()->acknowledged_by)->toBe($this->a2Chief->id);
    CrossFixtures::signIn($this->a2Chief)->post("/disseminations/{$row->ulid}/revoke", ['note' => 'Not ours.'])->assertForbidden();
    CrossFixtures::signIn($this->a2Chief)->post("/disseminations/{$row->ulid}/acknowledge")->assertForbidden();
    Notification::assertSentTo($this->owner, DisseminationNotification::class, fn (DisseminationNotification $n) => $n->event === 'acknowledged' && $n->level->value === 'info');
});

test('a flashpoint shared with the user is not offered as a link target', function () {
    $foreign = F::flashpoint($this->a2Lga);
    $a2Threat = T::threat($this->a2Lga, ['created_by' => $this->a2Director->id]);
    disseminate($this->a2Director, $foreign, [['state', $this->world->states['A1']]])->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->owner)->get("/flashpoints/{$foreign->ulid}")->assertOk();
    CrossFixtures::signIn($this->owner)->post("/threats/{$this->threat->ulid}/links/flashpoints", ['record' => $foreign->ulid])->assertForbidden();
    CrossFixtures::signIn($this->owner)->postJson('/pickers/flashpoints')->assertOk()->assertJsonMissing(['value' => $foreign->ulid]);
    expect($a2Threat)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Linked records and matches
|--------------------------------------------------------------------------
*/

test('linked records stay under their own scopes, and matches appear only when both sides are visible', function () {
    $ownIncident = F::incident($this->world->lgas['A1a'], $this->ownerChief);
    $a2Incident = F::incident($this->a2Lga, $this->a2Chief);
    CrossFixtures::system();
    foreach ([$ownIncident, $a2Incident] as $incident) {
        ThreatReportIncident::query()->forceCreate([
            'ulid' => (string) str()->ulid(), 'threat_report_id' => $this->threat->id, 'incident_id' => $incident->id,
            'state_id' => $this->threat->state_id, 'lga_id' => $this->threat->lga_id, 'relation' => 'related',
            'linked_by' => $this->owner->id, 'linked_at' => now(),
        ]);
    }
    $matched = F::incident($this->a2Lga, $this->a2Chief, 0.01);
    DB::table('threat_report_incident_matches')->insert(['threat_report_id' => $this->threat->id, 'incident_id' => $matched->id, 'matched_at' => now()]);

    // Before dissemination the A2 chief sees neither the link nor the match.
    CrossFixtures::signIn($this->a2Chief)->get("/incidents/{$a2Incident->ulid}")->assertInertia(fn (Assert $page) => $page->has('threats.linked', 0));
    CrossFixtures::signIn($this->a2Chief)->get("/incidents/{$matched->ulid}")->assertInertia(fn (Assert $page) => $page->has('threats.possible', 0));

    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);

    showThreat($this->a2Chief, $this->threat)->assertInertia(fn (Assert $page) => $page
        ->has('links.incidents', 1)
        ->where('links.incidents.0.ulid', $a2Incident->ulid)
        ->has('links.flashpoints', 0));
    showThreat($this->owner, $this->threat)->assertInertia(fn (Assert $page) => $page->has('links.incidents', 1)->where('links.incidents.0.ulid', $ownIncident->ulid));
    // The match is now visible (both sides); the link from the incident side
    // sits on the threat's place and is not shown on the incident page.
    CrossFixtures::signIn($this->a2Chief)->get("/incidents/{$matched->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('threats.possible', 1)
        ->where('threats.possible.0.ulid', $this->threat->ulid)
        ->where('threats.possible.0.can_materialise', false));
});

/*
|--------------------------------------------------------------------------
| Onward dissemination
|--------------------------------------------------------------------------
*/

test('a recipient state forwards only to its own LGAs; revoking the parent revokes the children', function () {
    Event::fake([DisseminationEnded::class]);
    disseminate($this->owner, $this->threat, [['state', $this->a2]]);
    $parent = onlyRow();
    $onward = fn (array $targets) => CrossFixtures::signIn($this->a2Director)->post("/disseminations/{$parent->ulid}/onward", [
        'targets' => $targets, 'reason' => 'Forwarding to the LGA on the border with A1.',
    ]);

    $onward([['level' => 'state', 'ulid' => $this->world->states['B1']->ulid]])->assertSessionHasErrors('targets');
    $onward([['level' => 'lga', 'ulid' => $this->world->lgas['B1']->ulid]])->assertSessionHasErrors('targets');
    Notification::fake();
    $onward([['level' => 'lga', 'ulid' => $this->a2Lga->ulid]])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->a2Chief, DisseminationNotification::class, fn ($n) => $n->event === 'disseminated');
    Notification::assertNotSentTo([$this->a2Director, $this->a2Deputy], DisseminationNotification::class);

    $child = onlyRow();
    expect($child->parent_dissemination_id)->toBe($parent->id)
        ->and($child->expires_at->equalTo($parent->expires_at))->toBeTrue();
    showThreat($this->a2Chief, $this->threat)->assertOk()->assertInertia(fn (Assert $page) => $page->where('dissemination.source', fn ($s) => str_contains((string) $s, ' via ')));

    // Only the recipient state's SD/DSD may forward.
    CrossFixtures::signIn($this->b1Director)->post("/disseminations/{$parent->ulid}/onward", ['targets' => [['level' => 'lga', 'ulid' => $this->world->lgas['B1']->ulid]], 'reason' => str_repeat('x', 25)])->assertNotFound();

    CrossFixtures::signIn($this->owner)->post("/disseminations/{$parent->ulid}/revoke", ['note' => 'Situation resolved.'])->assertSessionHasNoErrors();
    expect($child->refresh()->revoked_at)->not->toBeNull();
    showThreat($this->a2Director, $this->threat)->assertNotFound();
    showThreat($this->a2Chief, $this->threat)->assertNotFound();
    Event::assertDispatched(DisseminationEnded::class, fn (DisseminationEnded $e) => $e->disseminationUlid === $child->ulid && $e->holderUlid === $this->a2Chief->ulid);
});

/*
|--------------------------------------------------------------------------
| Expiry follows the threat
|--------------------------------------------------------------------------
*/

test('threat report disseminations follow the threat\'s expiry when extended or reactivated', function () {
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);
    expect(onlyRow()->expires_at->equalTo($this->threat->expires_at))->toBeTrue();

    $new = CarbonImmutable::now()->addDays(30)->startOfMinute();
    CrossFixtures::signIn($this->owner)->post("/threats/{$this->threat->ulid}/extend", ['expires_at' => $new->format('Y-m-d\TH:i'), 'comment' => 'Still credible.'])->assertSessionHasNoErrors();
    expect(onlyRow()->expires_at->equalTo($new))->toBeTrue();

    $this->travel(31)->days();
    $this->artisan('buffalo:expire-threat-reports')->assertSuccessful();
    $this->artisan('buffalo:process-disseminations')->assertSuccessful();
    showThreat($this->a2Chief, $this->threat)->assertNotFound();

    $again = CarbonImmutable::now()->addDays(10)->startOfMinute();
    CrossFixtures::signIn($this->owner)->post("/threats/{$this->threat->ulid}/reactivate", ['expires_at' => $again->format('Y-m-d\TH:i'), 'comment' => 'New reports.'])->assertSessionHasNoErrors();
    expect(onlyRow()->expires_at->equalTo($again))->toBeTrue()->and(onlyRow()->expiry_notified_at)->toBeNull();
    showThreat($this->a2Chief, $this->threat)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Target suggestions
|--------------------------------------------------------------------------
*/

test('suggested targets use boundaries, else centroids within the radius plus the margin', function () {
    $threat = ThreatReport::query()->withoutGlobalScopes()->whereKey($this->threat->id)->sole();
    DB::table('threat_reports')->where('id', $threat->id)->update(['location' => DB::raw('ST_SetSRID(ST_MakePoint(3.4, 6.5), 4326)::geography'), 'radius_meters' => 5000]);
    $threat->refresh();
    $square = fn (float $lng, float $lat, float $d) => DB::raw(sprintf(
        "ST_Multi(ST_GeomFromText('POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))', 4326))::geography",
        $lng - $d, $lat - $d, $lng + $d, $lat - $d, $lng + $d, $lat + $d, $lng - $d, $lat + $d, $lng - $d, $lat - $d,
    ));
    $targets = app(DisseminationTargets::class);

    // Centroids only: A1b 20 km away (within 5 km + 20 km), A2 30 km away (outside).
    DB::table('lgas')->where('id', $this->world->lgas['A1b']->id)->update(['centroid' => DB::raw('ST_SetSRID(ST_MakePoint(3.58, 6.5), 4326)::geography')]);
    DB::table('lgas')->where('id', $this->a2Lga->id)->update(['centroid' => DB::raw('ST_SetSRID(ST_MakePoint(3.67, 6.5), 4326)::geography')]);
    $names = collect($targets->present($targets->suggest($threat)))->pluck('name');
    expect($names)->toContain($this->world->lgas['A1b']->name.' LGA, '.$this->world->states['A1']->name)
        ->and($names->filter(fn ($n) => str_contains($n, $this->a2Lga->name))->all())->toBe([]);

    // Boundaries: A2's boundary reaches within 5 km, so A2 (LGA and state) is suggested;
    // A1b's boundary is far away, so its centroid no longer counts.
    DB::table('lgas')->where('id', $this->a2Lga->id)->update(['boundary' => $square(3.42, 6.5, 0.01)]);
    DB::table('lgas')->where('id', $this->world->lgas['A1b']->id)->update(['boundary' => $square(4.5, 7.5, 0.01)]);
    $suggested = collect($targets->suggest($threat));
    expect($suggested->where('level', DisseminationTargetLevel::Lga)->pluck('lga_id')->all())->toBe([$this->a2Lga->id])
        ->and($suggested->where('level', DisseminationTargetLevel::State)->pluck('state_id')->all())->toBe([$this->a2->id]);

    // Covered once disseminated.
    disseminate($this->owner, $this->threat, [['state', $this->a2]]);
    expect($targets->uncovered($threat))->toBe([]);
});

test('creating an urgent threat whose area crosses a border prompts the creator', function () {
    DB::table('lgas')->where('id', $this->world->lgas['A1b']->id)->update(['centroid' => DB::raw('ST_SetSRID(ST_MakePoint(3.45, 6.5), 4326)::geography')]);
    $payload = T::payload($this->world->lgas['A1a'], $this->threat->threatType, ['latitude' => 6.5, 'longitude' => 3.4, 'radius_meters' => 2000]);

    CrossFixtures::signIn($this->owner)->post('/threats', $payload)->assertSessionHasNoErrors();
    $created = ThreatReport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->get("/threats/{$created->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('disseminationPrompt.mode', 'disseminate')
        ->where('disseminationPrompt.places', [$this->world->lgas['A1b']->name.' LGA, '.$this->world->states['A1']->name]));
    // Only once.
    $this->get("/threats/{$created->ulid}")->assertInertia(fn (Assert $page) => $page->where('disseminationPrompt', null));

    CrossFixtures::signIn($this->ownerChief)->post('/threats', [...$payload, 'title' => 'Second warning'])->assertSessionHasNoErrors();
    $second = ThreatReport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->get("/threats/{$second->ulid}")->assertInertia(fn (Assert $page) => $page->where('disseminationPrompt.mode', 'request'));
});

/*
|--------------------------------------------------------------------------
| Requests from LGA chiefs
|--------------------------------------------------------------------------
*/

test('an LGA chief requests dissemination; the SD actions or declines it', function () {
    $flashpoint = F::flashpoint($this->world->lgas['A1a']);
    $ask = fn () => CrossFixtures::signIn($this->ownerChief)->post("/flashpoints/{$flashpoint->ulid}/dissemination-requests", [
        'targets' => [['level' => 'lga', 'ulid' => $this->world->lgas['A1b']->ulid]],
        'reason' => 'The corridor runs into A1b; their patrols need to know.',
    ]);

    $ask()->assertSessionHasNoErrors();
    $ask()->assertSessionHasErrors('reason');
    Notification::assertSentTo([$this->owner, $this->ownerDeputy], DisseminationNotification::class, fn (DisseminationNotification $n) => $n->event === 'requested' && $n->level->value === 'warning');
    CrossFixtures::signIn($this->ownerChief)->post("/flashpoints/{$flashpoint->ulid}/disseminations", ['targets' => [['level' => 'lga', 'ulid' => $this->world->lgas['A1b']->ulid]], 'reason' => str_repeat('x', 25)])->assertForbidden();

    $request = DisseminationRequest::query()->sole();
    CrossFixtures::signIn($this->owner)->get('/disseminations?tab=requests')->assertInertia(fn (Assert $page) => $page
        ->has('requests', 1)->where('requests.0.can_decide', true)->where('requests.0.targets.0.name', $this->world->lgas['A1b']->name.' LGA, '.$this->world->states['A1']->name));

    CrossFixtures::signIn($this->owner)->post("/flashpoints/{$flashpoint->ulid}/disseminations", [
        'targets' => [['level' => 'lga', 'ulid' => $this->world->lgas['A1b']->ulid]],
        'reason' => 'As requested by the LGA chief: corridor reaches A1b.',
        'request' => $request->ulid,
    ])->assertSessionHasNoErrors();
    expect($request->refresh()->status->value)->toBe('actioned');
    CrossFixtures::signIn($this->neighbourChief)->get("/flashpoints/{$flashpoint->ulid}")->assertOk();

    $ask()->assertSessionHasNoErrors();
    $second = DisseminationRequest::query()->where('status', 'pending')->sole();
    CrossFixtures::signIn($this->owner)->post("/dissemination-requests/{$second->ulid}/decline", ['note' => 'Already shared.'])->assertSessionHasNoErrors();
    expect($second->refresh()->status->value)->toBe('declined');
    Notification::assertSentTo($this->ownerChief, DisseminationNotification::class, fn (DisseminationNotification $n) => $n->event === 'declined');
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

test('notifications go to recipients with the severity of the record, and carry no content', function () {
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);

    Notification::assertSentTo([$this->a2Chief, $this->a2Director, $this->a2Deputy], DisseminationNotification::class, function (DisseminationNotification $n) {
        $payload = (string) json_encode($n->toArray($this->a2Chief), JSON_UNESCAPED_SLASHES);

        return $n->event === 'disseminated' && $n->level->value === 'critical'
            && str_contains($payload, $this->threat->reference_no)
            && str_contains($payload, 'Threat report')
            && str_contains($payload, $this->world->states['A1']->name.' State')
            && ! str_contains($payload, $this->threat->description)
            && ! str_contains($payload, $this->threat->title)
            && ! str_contains($payload, 'Armed men expected');
    });
    Notification::assertNotSentTo([$this->owner, $this->ownerDeputy, $this->ownerChief, $this->b1Director], DisseminationNotification::class);

    // A priority threat to a state: SD and DSD, warning.
    Notification::fake();
    $priority = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Priority]);
    disseminate($this->owner, $priority, [['state', $this->a2]]);
    Notification::assertSentTo([$this->a2Director, $this->a2Deputy], DisseminationNotification::class, fn ($n) => $n->level->value === 'warning');
    Notification::assertNotSentTo($this->a2Chief, DisseminationNotification::class);

    // A flashpoint by concern: severe → critical.
    Notification::fake();
    $severe = F::flashpoint($this->world->lgas['A1a'], ['concern_level' => 'severe']);
    disseminate($this->owner, $severe, [['state', $this->a2]]);
    Notification::assertSentTo($this->a2Director, DisseminationNotification::class, fn ($n) => $n->level->value === 'critical' && $n->kind === 'Flashpoint');
});

test('unacknowledged disseminations are reminded once: after 2 hours for immediate threats, 24 for others', function () {
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);
    $flashpoint = F::flashpoint($this->world->lgas['A1a']);
    disseminate($this->owner, $flashpoint, [['lga', $this->a2Lga]]);
    Notification::fake();
    $reminders = fn () => Notification::sent($this->a2Chief, DisseminationNotification::class, fn ($n) => $n->event === 'reminder')->count();

    $this->travel(1)->hours();
    $this->artisan('buffalo:process-disseminations');
    expect($reminders())->toBe(0);

    $this->travel(2)->hours();
    $this->artisan('buffalo:process-disseminations');
    expect($reminders())->toBe(1);
    Notification::assertSentTo($this->a2Chief, DisseminationNotification::class, fn ($n) => $n->event === 'reminder' && $n->level->value === 'warning');

    $this->artisan('buffalo:process-disseminations');
    expect($reminders())->toBe(1);

    $this->travel(22)->hours();
    $this->artisan('buffalo:process-disseminations');
    expect($reminders())->toBe(2);

    // Acknowledged ones are not reminded.
    Notification::fake();
    $other = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate]);
    disseminate($this->owner, $other, [['lga', $this->a2Lga]]);
    CrossFixtures::signIn($this->a2Chief)->post('/disseminations/'.onlyRow()->ulid.'/acknowledge');
    $this->travel(3)->hours();
    $this->artisan('buffalo:process-disseminations');
    expect($reminders())->toBe(0);
});

test('the daily check reports urgent cross-border records without dissemination once', function () {
    DB::table('threat_reports')->where('id', $this->threat->id)->update(['location' => DB::raw('ST_SetSRID(ST_MakePoint(3.4, 6.5), 4326)::geography'), 'radius_meters' => 5000]);
    DB::table('lgas')->where('id', $this->a2Lga->id)->update(['centroid' => DB::raw('ST_SetSRID(ST_MakePoint(3.45, 6.5), 4326)::geography')]);
    $gaps = fn () => Notification::sent($this->owner, DisseminationNotification::class, fn ($n) => $n->event === 'gap')->count();

    $this->artisan('buffalo:check-dissemination-gaps')->assertSuccessful();
    expect($gaps())->toBe(1);
    Notification::assertSentTo($this->ownerDeputy, DisseminationNotification::class, fn ($n) => $n->event === 'gap');

    $this->artisan('buffalo:check-dissemination-gaps');
    expect($gaps())->toBe(1);

    CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('crossBorder.count', 1)
        ->where('crossBorder.rows.0.label', $this->threat->reference_no));
});

/*
|--------------------------------------------------------------------------
| Inbox, dashboards, audit
|--------------------------------------------------------------------------
*/

test('the inbox lists shared records unacknowledged first; dashboards show the unacknowledged count', function () {
    $older = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Routine]);
    disseminate($this->owner, $older, [['lga', $this->a2Lga]]);
    CrossFixtures::signIn($this->a2Chief)->post('/disseminations/'.onlyRow()->ulid.'/acknowledge');
    disseminate($this->owner, $this->threat, [['lga', $this->a2Lga]]);

    CrossFixtures::signIn($this->a2Chief)->get('/disseminations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('disseminations/index')
        ->has('received', 2)
        ->where('received.0.subject.label', $this->threat->reference_no)
        ->where('received.0.can_acknowledge', true)
        ->where('received.1.status', 'acknowledged')
        ->where('can.requests', false));

    CrossFixtures::signIn($this->a2Chief)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('sharedWithUs.count', 1)
        ->where('badges.disseminations', 1));

    CrossFixtures::signIn($this->owner)->get('/disseminations?tab=sent')->assertInertia(fn (Assert $page) => $page->has('sent', 2));
    CrossFixtures::signIn($this->b1Director)->get('/disseminations?tab=sent')->assertInertia(fn (Assert $page) => $page->has('sent', 0));
});

test('every action is a named audit event and recipients\' views are logged with context dissemination', function () {
    $flashpoint = F::flashpoint($this->world->lgas['A1a']);
    disseminate($this->owner, $flashpoint, [['lga', $this->a2Lga]]);
    $row = onlyRow();
    CrossFixtures::signIn($this->a2Chief)->get("/flashpoints/{$flashpoint->ulid}")->assertOk();
    CrossFixtures::signIn($this->a2Chief)->post("/disseminations/{$row->ulid}/acknowledge");
    CrossFixtures::signIn($this->owner)->post("/disseminations/{$row->ulid}/renew");
    CrossFixtures::signIn($this->owner)->post("/disseminations/{$row->ulid}/revoke", ['note' => 'No longer needed.']);

    expect(Activity::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['disseminated', 'acknowledged', 'renewed', 'revoked']);
    expect(RecordAccessLog::query()->where('user_id', $this->a2Chief->id)->where('subject_id', $flashpoint->id)->where('action', AccessAction::View->value)->value('context'))
        ->toBe('dissemination');

    // Owners' own views keep their usual context (restricted: not logged).
    CrossFixtures::signIn($this->owner)->get("/flashpoints/{$flashpoint->ulid}")->assertOk();
    expect(RecordAccessLog::query()->where('user_id', $this->owner->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Permissions and architecture
|--------------------------------------------------------------------------
*/

test('dissemination needs the permission and the "who" rule', function () {
    $other = T::threat($this->world->lgas['B1']);

    // DSD of the owning state may disseminate but not revoke.
    disseminate($this->ownerDeputy, $this->threat, [['lga', $this->a2Lga]])->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->ownerDeputy)->post('/disseminations/'.onlyRow()->ulid.'/revoke', ['note' => 'Not needed.'])->assertForbidden();
    // Another state's SD cannot disseminate A1's record.
    disseminate($this->b1Director, $this->threat, [['state', $this->a2]])->assertNotFound();
    // A1's SD cannot disseminate B1's record.
    disseminate($this->owner, $other, [['state', $this->a2]])->assertNotFound();
    // DG and DDGs covering the state may.
    disseminate($this->world->users['director_general'], $this->threat, [['state', $this->a2]])->assertSessionHasNoErrors();
    disseminate($this->world->users['ddg_zone_b'], $other, [['state', $this->a2]])->assertSessionHasNoErrors();
    disseminate($this->world->users['ddg_zone_b'], $this->threat, [['state', $this->world->states['B1']]])->assertNotFound();
    // Super admin: nothing.
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/disseminations')->assertForbidden();
});

test('only ThreatReport and Flashpoint are disseminable', function () {
    $disseminable = collect(File::allFiles(app_path('Models')))
        ->map(fn ($file) => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Disseminable::class))
        ->sort()->values()->all();

    expect($disseminable)->toBe([Flashpoint::class, ThreatReport::class])
        ->and(array_values(RecordDissemination::SUBJECTS))->toBe([ThreatReport::class, Flashpoint::class]);

    // The resolver adds nothing for any other scoped model.
    $resolver = app(DisseminationResolver::class);
    $query = Incident::query();
    $before = $query->toBase()->wheres;
    $resolver->addGrantedRecords($query, new Incident, $this->a2Chief);
    expect($query->toBase()->wheres)->toBe($before);
});
