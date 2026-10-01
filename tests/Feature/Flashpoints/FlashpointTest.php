<?php

use App\Enums\Classification;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\RecordAccessLog;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->threat = ThreatType::factory()->create(['name' => 'Kidnapping']);
});

/*
|--------------------------------------------------------------------------
| Policy and visibility
|--------------------------------------------------------------------------
*/

test('each role may view, manage, assess and delete flashpoints only as the matrix and jurisdiction allow', function () {
    $flashpoint = F::flashpoint($this->lga);
    $abilities = fn (User $u) => array_keys(array_filter([
        'view' => $u->can('view', $flashpoint),
        'update' => $u->can('update', $flashpoint),
        'assess' => $u->can('assess', $flashpoint),
        'delete' => $u->can('delete', $flashpoint),
    ]));
    $all = ['view', 'update', 'assess', 'delete'];
    $noDelete = ['view', 'update', 'assess'];

    $expected = [
        'super_admin' => [],
        'director_general' => $all,
        'ddg_national' => $all,
        'ddg_zone_a' => $all,
        'ddg_zone_b' => [],
        'state_director' => $all,
        'deputy_state_director' => $noDelete,
        'lga_chief' => $noDelete,
    ];

    foreach ($expected as $key => $abilitiesExpected) {
        expect($abilities($this->world->users[$key]))->toBe($abilitiesExpected, $key);
    }

    // Officers of another LGA or state are denied.
    expect($abilities(User::factory()->lgaChief($this->world->lgas['A1b'])->create()))->toBe([])
        ->and($abilities(User::factory()->stateDirector($this->world->states['B1'])->create()))->toBe([]);
});

test('flashpoints outside the jurisdiction are invisible in lists, maps and by URL', function () {
    $own = F::flashpoint($this->lga, ['name' => 'Own LGA']);
    $sameState = F::flashpoint($this->world->lgas['A1b'], ['name' => 'Same state']);
    $other = F::flashpoint($this->world->lgas['B1'], ['name' => 'Other zone']);

    CrossFixtures::signIn($this->world->users['lga_chief'])->get('/flashpoints')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('flashpoints/index')
            ->where('flashpoints.data.0.ulid', $own->ulid)
            ->has('flashpoints.data', 1)
            ->has('areas', 1));

    $this->get("/flashpoints/{$sameState->ulid}")->assertNotFound();
    $this->get("/flashpoints/{$other->ulid}")->assertNotFound();

    CrossFixtures::signIn($this->world->users['state_director'])->get('/flashpoints')
        ->assertInertia(fn (Assert $page) => $page->has('flashpoints.data', 2));
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/flashpoints')->assertForbidden();
});

test('classification narrows visibility within the jurisdiction, and classified views are access-logged', function () {
    $secret = F::flashpoint($this->lga, ['name' => 'Secret one'], Classification::Secret);
    $confidential = F::flashpoint($this->lga, ['name' => 'Confidential one'], Classification::Confidential);

    $sees = function (string $key): array {
        $names = [];
        CrossFixtures::signIn($this->world->users[$key])->get('/flashpoints')
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = collect($page->toArray()['props']['flashpoints']['data'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    };

    expect($sees('lga_chief'))->toBe([])
        ->and($sees('deputy_state_director'))->toBe(['Confidential one'])
        ->and($sees('state_director'))->toBe(['Confidential one', 'Secret one']);
    $this->get("/flashpoints/{$confidential->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/flashpoints/{$secret->ulid}")->assertOk();

    expect(RecordAccessLog::query()->where('subject_id', $secret->id)->where('subject_type', $secret->getMorphClass())->exists())->toBeTrue();

    // An LGA chief may not classify above their clearance.
    CrossFixtures::signIn($this->world->users['lga_chief'])
        ->post('/flashpoints', F::payload($this->lga, $this->threat, ['classification' => 'secret']))
        ->assertSessionHasErrors('classification');
});

/*
|--------------------------------------------------------------------------
| Creating, editing and assessing
|--------------------------------------------------------------------------
*/

test('creating a flashpoint records its first assessment and sets the review date from the concern level', function () {
    CrossFixtures::signIn($this->world->users['lga_chief'])
        ->post('/flashpoints', F::payload($this->lga, $this->threat))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    CrossFixtures::system();
    $flashpoint = Flashpoint::query()->sole();
    $assessment = FlashpointAssessment::query()->sole();

    expect($flashpoint->kind->value)->toBe('blackspot')
        ->and($flashpoint->concern_level->value)->toBe('high')
        ->and($flashpoint->radius_meters)->toBe(5000)
        ->and($flashpoint->state_id)->toBe($this->lga->state_id)
        ->and($flashpoint->review_due_at->toDateString())->toBe(CarbonImmutable::today()->addDays(60)->toDateString())
        ->and($flashpoint->recommended_action)->toBe('Joint patrols at dusk.')
        ->and($flashpoint->last_assessed_by)->toBe($this->world->users['lga_chief']->id)
        ->and($assessment->flashpoint_id)->toBe($flashpoint->id)
        ->and($assessment->lga_id)->toBe($this->lga->id)
        ->and($assessment->assessment)->toBe('Three abductions in two months; gangs move at dusk.');

    expect(Activity::query()->where('subject_id', $flashpoint->id)->where('event', 'assessed')->exists())->toBeTrue();
});

test('a reassessment raising concern to severe moves the review date to 30 days; edits cannot change assessed fields', function () {
    $flashpoint = F::flashpoint($this->lga, ['concern_level' => 'moderate']);
    $sd = $this->world->users['state_director'];

    CrossFixtures::signIn($sd)->post("/flashpoints/{$flashpoint->ulid}/assessments", [
        'concern_level' => 'severe',
        'status' => 'active',
        'assessment' => 'Two abductions this week.',
        'recommended_action' => 'Checkpoint at the junction.',
    ])->assertSessionHasNoErrors();

    CrossFixtures::system();
    $flashpoint->refresh();
    expect($flashpoint->concern_level->value)->toBe('severe')
        ->and($flashpoint->review_due_at->toDateString())->toBe(CarbonImmutable::today()->addDays(30)->toDateString())
        ->and($flashpoint->recommended_action)->toBe('Checkpoint at the junction.');

    $event = Activity::query()->where('subject_id', $flashpoint->id)->where('event', 'assessed')->sole();
    expect($event->properties['old']['concern_level'])->toBe('moderate')
        ->and($event->properties['new']['concern_level'])->toBe('severe');

    // The edit form may not touch them.
    CrossFixtures::signIn($sd)->put("/flashpoints/{$flashpoint->ulid}", [
        ...F::payload($this->lga, $this->threat),
        'concern_level' => 'low',
    ])->assertSessionHasErrors(['concern_level', 'status', 'assessment']);
});

test('assessments are append-only', function () {
    CrossFixtures::signIn($this->world->users['state_director'])
        ->post('/flashpoints', F::payload($this->lga, $this->threat))
        ->assertSessionHasNoErrors();
    CrossFixtures::system();
    $id = FlashpointAssessment::query()->sole()->id;

    expect(fn () => DB::transaction(fn () => DB::table('flashpoint_assessments')->where('id', $id)->update(['assessment' => 'Rewritten'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('flashpoint_assessments')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only');
});

test('moving a flashpoint to another LGA carries its assessments and links along', function () {
    $flashpoint = F::flashpoint($this->lga);
    $incident = F::incident($this->lga, $this->world->users['lga_chief']);
    CrossFixtures::signIn($this->world->users['state_director'])->post("/flashpoints/{$flashpoint->ulid}/assessments", ['concern_level' => 'low', 'status' => 'monitoring', 'assessment' => 'Calm.'])->assertSessionHasNoErrors();
    $this->post("/flashpoints/{$flashpoint->ulid}/incidents", ['record' => $incident->ulid])->assertSessionHasNoErrors();

    $this->put("/flashpoints/{$flashpoint->ulid}", [
        ...F::payload($this->world->lgas['A1b'], $this->threat),
        'concern_level' => null, 'status' => null, 'assessment' => null, 'recommended_action' => null,
    ])->assertSessionHasNoErrors();

    $lga = $this->world->lgas['A1b']->id;
    expect(DB::table('flashpoints')->where('id', $flashpoint->id)->value('lga_id'))->toBe($lga)
        ->and(DB::table('flashpoint_assessments')->where('flashpoint_id', $flashpoint->id)->pluck('lga_id')->unique()->all())->toBe([$lga])
        ->and(DB::table('flashpoint_incidents')->where('flashpoint_id', $flashpoint->id)->value('lga_id'))->toBe($lga);
});

test('the point must lie inside the LGA boundary and the radius between 100 m and 50 km', function () {
    DB::statement("UPDATE lgas SET boundary = ST_GeogFromText('SRID=4326;MULTIPOLYGON(((3.3 6.4, 3.5 6.4, 3.5 6.6, 3.3 6.6, 3.3 6.4)))') WHERE id = ?", [$this->lga->id]);
    $chief = $this->world->users['lga_chief'];

    CrossFixtures::signIn($chief)->post('/flashpoints', F::payload($this->lga, $this->threat, ['latitude' => 7.5]))
        ->assertSessionHasErrors(['longitude' => "The location is outside the boundary of {$this->lga->name} LGA."]);
    $this->post('/flashpoints', F::payload($this->lga, $this->threat, ['radius_meters' => 50]))->assertSessionHasErrors('radius_meters');
    $this->post('/flashpoints', F::payload($this->lga, $this->threat, ['radius_meters' => 60000]))->assertSessionHasErrors('radius_meters');
    $this->post('/flashpoints', F::payload($this->lga, $this->threat))->assertSessionHasNoErrors();

    // The database refuses it too.
    CrossFixtures::system();
    expect(fn () => DB::transaction(fn () => Flashpoint::factory()->in($this->lga)->at(7.5, 3.4)->create()))->toThrow(QueryException::class, 'outside its LGA boundary')
        ->and(fn () => DB::transaction(fn () => Flashpoint::factory()->in($this->lga)->at(6.5, 3.4, 99)->create()))->toThrow(QueryException::class, 'radius');
});

test('a threat type used by a flashpoint is in use', function () {
    $usage = app(ReferenceUsage::class);
    expect($usage->isInUse($this->threat))->toBeFalse();

    F::flashpoint($this->lga, ['threat_type_id' => $this->threat->id]);

    expect($usage->isInUse($this->threat))->toBeTrue();
});

test('deleting needs flashpoints.delete and a reason, and keeps the record', function () {
    $flashpoint = F::flashpoint($this->lga);

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->delete("/flashpoints/{$flashpoint->ulid}", ['reason' => 'x'])->assertForbidden();
    CrossFixtures::signIn($this->world->users['state_director'])->delete("/flashpoints/{$flashpoint->ulid}", ['reason' => 'Duplicate entry'])->assertRedirect('/flashpoints');

    CrossFixtures::system();
    expect(Flashpoint::query()->find($flashpoint->id))->toBeNull()
        ->and(Flashpoint::withTrashed()->find($flashpoint->id))->not->toBeNull()
        ->and(Activity::query()->where('event', 'flashpoint_deleted')->value('properties')['reason'] ?? null)->toBe('Duplicate entry');
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

test('the detail page, LGA tab and incident page show flashpoints the viewer can see', function () {
    $flashpoint = F::flashpoint($this->lga, ['name' => 'Waterfront']);
    $incident = F::incident($this->lga, $this->world->users['lga_chief']);

    CrossFixtures::signIn($this->world->users['lga_chief']);

    foreach (['overview', 'assessments', 'incidents', 'security_notes', 'history'] as $tab) {
        $this->get("/flashpoints/{$flashpoint->ulid}?tab={$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab));
    }

    $this->get("/lgas/{$this->lga->ulid}?tab=flashpoints")->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'flashpoints')
        ->where('flashpoints.rows.0.name', 'Waterfront'));

    $this->get("/incidents/{$incident->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('flashpoints.near.0.ulid', $flashpoint->ulid)
        ->has('flashpoints.linked', 0));

    // No numeric ids reach the page.
    $this->get("/flashpoints/{$flashpoint->ulid}")->assertInertia(fn (Assert $page) => $page
        ->missing('flashpoint.id')
        ->missing('flashpoint.lga_id'));
});
