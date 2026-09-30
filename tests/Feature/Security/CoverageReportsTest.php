<?php

use App\Enums\Classification;
use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Notifications\CoverageCriticalRating;
use App\Notifications\CoverageReportReturned;
use App\Notifications\CoverageReportReviewed;
use App\Notifications\CoverageReportSubmitted;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Security\CoverageAnalysis;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->agency = SecurityAgency::factory()->create(['name' => 'Nigeria Police Force']);
    $this->communities = collect(['Ajegunle', 'Bariga', 'Coker'])
        ->map(fn (string $name) => Community::factory()->in($this->lga)->create(['name' => $name]));
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function coveragePayload(array $overrides = []): array
{
    return [
        'lga' => test()->lga->ulid,
        'period_type' => 'monthly',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'overall_rating' => 'partial',
        'summary' => 'Police cover the town centre; the riverside is thin.',
        'gaps_identified' => 'No night patrols at the riverside.',
        'recommendations' => 'Add a marine unit.',
        'classification' => 'restricted',
        'deployments' => [[
            'agency' => test()->agency->ulid,
            'personnel_count' => 40,
            'vehicles_count' => 4,
            'patrol_frequency' => 'daily',
            'communities' => [test()->communities[0]->ulid],
            'areas_covered' => 'Town centre',
        ]],
        ...$overrides,
    ];
}

function coverageReport(User $by, array $overrides = []): CoverageReport
{
    CrossFixtures::signIn($by)->post('/coverage-reports', coveragePayload($overrides))->assertSessionHasNoErrors();
    CrossFixtures::system();

    return CoverageReport::query()->latest('id')->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Scope and classification
|--------------------------------------------------------------------------
*/

test('reports and their deployments follow the jurisdiction matrix', function (string $who) {
    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        $report = CoverageReport::factory()->in($this->world->lgas[$lga])->create(['summary' => $label]);
        $deployment = new CoverageReportDeployment;
        $deployment->forceFill([
            'coverage_report_id' => $report->id, 'state_id' => $report->state_id, 'lga_id' => $report->lga_id,
            'security_agency_id' => $this->agency->id, 'personnel_count' => 1, 'patrol_frequency' => 'daily', 'remarks' => $label,
        ])->save();
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    [$reports, $deployments] = app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => [
        CoverageReport::query()->orderBy('summary')->pluck('summary')->all(),
        CoverageReportDeployment::query()->orderBy('remarks')->pluck('remarks')->all(),
    ]);

    expect($reports)->toBe($expected)->and($deployments)->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('classification narrows reports and their deployments', function () {
    foreach (Classification::cases() as $level) {
        $report = CoverageReport::factory()->in($this->lga)->classified($level)->create(['period_type' => 'ad_hoc', 'period_start' => '2026-09-01', 'period_end' => '2026-09-02', 'summary' => $level->value]);
        $deployment = new CoverageReportDeployment;
        $deployment->forceFill([
            'coverage_report_id' => $report->id, 'state_id' => $report->state_id, 'lga_id' => $report->lga_id,
            'security_agency_id' => $this->agency->id, 'personnel_count' => 1, 'patrol_frequency' => 'daily', 'remarks' => $level->value,
        ])->save();
    }

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => [
        CoverageReport::query()->orderBy('summary')->pluck('summary')->all(),
        CoverageReportDeployment::query()->orderBy('remarks')->pluck('remarks')->all(),
    ]);

    expect($visible('lga_chief'))->toBe([['restricted'], ['restricted']])
        ->and($visible('deputy_state_director'))->toBe([['confidential', 'restricted'], ['confidential', 'restricted']])
        ->and($visible('state_director'))->toBe([['confidential', 'restricted', 'secret'], ['confidential', 'restricted', 'secret']])
        ->and($visible('super_admin'))->toBe([[], []]);

    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload(['classification' => 'confidential']))
        ->assertSessionHasErrors('classification');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

test('regular periods must match their boundaries', function (string $type, string $start, string $end, bool $valid) {
    $response = CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload(['period_type' => $type, 'period_start' => $start, 'period_end' => $end]));

    $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('period_start');
})->with([
    'week Monday to Sunday' => ['weekly', '2026-09-28', '2026-10-04', true],
    'week from a Tuesday' => ['weekly', '2026-09-29', '2026-10-05', false],
    'calendar month' => ['monthly', '2026-09-01', '2026-09-30', true],
    'month cut short' => ['monthly', '2026-09-01', '2026-09-29', false],
    'calendar quarter' => ['quarterly', '2026-07-01', '2026-09-30', true],
    'quarter misaligned' => ['quarterly', '2026-08-01', '2026-10-31', false],
    'ad hoc any range' => ['ad_hoc', '2026-09-03', '2026-09-17', true],
]);

test('the database enforces period boundaries too', function () {
    expect(fn () => DB::transaction(fn () => CoverageReport::factory()->in($this->lga)->create(['period_start' => '2026-09-02', 'period_end' => '2026-09-30'])))
        ->toThrow(QueryException::class, 'coverage_reports_period_boundaries');
});

test('one report per LGA and period; ad hoc reports are not limited', function () {
    coverageReport($this->chief);

    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload())
        ->assertSessionHasErrors(['period_start' => "{$this->lga->name} LGA already has a Monthly report for September 2026."]);

    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload(['period_type' => 'ad_hoc']))->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload(['period_type' => 'ad_hoc']))->assertSessionHasNoErrors();

    // A discarded draft frees the period.
    CrossFixtures::system();
    $first = CoverageReport::query()->where('period_type', 'monthly')->sole();
    CrossFixtures::signIn($this->chief)->delete("/coverage-reports/{$first->ulid}")->assertRedirect();
    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload())->assertSessionHasNoErrors();
});

test('covered communities must be in the LGA; a formation must match the agency and state', function () {
    $elsewhere = Community::factory()->in($this->world->lgas['A1b'])->create();
    $army = SecurityAgency::factory()->create();
    $armyBase = AgencyFormation::factory()->of($army)->in($this->lga)->create();
    $otherState = AgencyFormation::factory()->of($this->agency)->in($this->world->lgas['B1'])->create();
    $ok = AgencyFormation::factory()->of($this->agency)->in($this->world->lgas['A1b'])->create();

    $row = coveragePayload()['deployments'][0];

    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['deployments' => [[...$row, 'communities' => [$elsewhere->ulid]]]]))
        ->assertSessionHasErrors('deployments.0.communities');
    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['deployments' => [[...$row, 'formation' => $armyBase->ulid]]]))
        ->assertSessionHasErrors('deployments.0.formation');
    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['deployments' => [[...$row, 'formation' => $otherState->ulid]]]))
        ->assertSessionHasErrors('deployments.0.formation');
    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['deployments' => [[...$row, 'formation' => $ok->ulid]]]))
        ->assertSessionHasNoErrors();

    // The database refuses both too.
    CrossFixtures::system();
    $deployment = CoverageReportDeployment::query()->sole();
    expect(fn () => DB::transaction(fn () => $deployment->forceFill(['agency_formation_id' => $armyBase->id])->save()))->toThrow(QueryException::class, 'formation must belong')
        ->and(fn () => DB::table('coverage_deployment_communities')->insert(['coverage_report_deployment_id' => $deployment->id, 'community_id' => $elsewhere->id, 'lga_id' => $elsewhere->lga_id]))->toThrow(QueryException::class);
});

test('submitting requires a rating, a summary, gaps unless adequate, and a deployment unless critical', function () {
    $draft = coverageReport($this->chief, ['overall_rating' => null, 'summary' => null, 'gaps_identified' => null, 'deployments' => []]);
    $url = "/coverage-reports/{$draft->ulid}";
    $base = ['classification' => 'restricted', 'recommendations' => null, 'submit' => true];

    CrossFixtures::signIn($this->chief)->put($url, [...$base, 'overall_rating' => null, 'summary' => null, 'gaps_identified' => null, 'deployments' => []])
        ->assertSessionHasErrors(['overall_rating', 'summary', 'deployments']);
    CrossFixtures::signIn($this->chief)->put($url, [...$base, 'overall_rating' => 'inadequate', 'summary' => 'x', 'gaps_identified' => null, 'deployments' => coveragePayload()['deployments']])
        ->assertSessionHasErrors('gaps_identified');
    CrossFixtures::signIn($this->chief)->put($url, [...$base, 'overall_rating' => 'adequate', 'summary' => 'x', 'gaps_identified' => null, 'deployments' => coveragePayload()['deployments']])
        ->assertSessionHasNoErrors();

    expect($draft->fresh()->status)->toBe(CoverageReportStatus::Submitted);

    // Critical: no deployments needed.
    $critical = coverageReport($this->chief, ['period_type' => 'ad_hoc', 'overall_rating' => 'critical', 'deployments' => [], 'submit' => true]);
    expect($critical->status)->toBe(CoverageReportStatus::Submitted);
});

/*
|--------------------------------------------------------------------------
| Workflow
|--------------------------------------------------------------------------
*/

test('who may create: the LGA chief for their own LGA, a State Director or Deputy for any LGA of the state', function () {
    CrossFixtures::signIn($this->chief)->post('/coverage-reports', coveragePayload(['lga' => $this->world->lgas['A1b']->ulid]))->assertSessionHasErrors('lga');

    // A1b has no chief: the State Director reports for it.
    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['lga' => $this->world->lgas['A1b']->ulid, 'deployments' => []]))->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->dsd)->post('/coverage-reports', coveragePayload(['lga' => $this->world->lgas['A1b']->ulid, 'period_type' => 'ad_hoc', 'deployments' => []]))->assertSessionHasNoErrors();

    foreach (['director_general', 'ddg_national', 'super_admin'] as $who) {
        CrossFixtures::signIn($this->world->users[$who])->get('/coverage-reports/create')->assertForbidden();
    }

    CrossFixtures::signIn($this->sd)->post('/coverage-reports', coveragePayload(['lga' => $this->world->lgas['B1']->ulid]))->assertSessionHasErrors('lga');
});

test('who may edit: the creator or the State Director or Deputy, while draft or returned', function () {
    $report = coverageReport($this->chief);
    $otherChief = User::factory()->lgaChief($this->lga)->create();

    expect($this->chief->can('update', $report))->toBeTrue()
        ->and($this->sd->can('update', $report))->toBeTrue()
        ->and($this->dsd->can('update', $report))->toBeTrue()
        ->and($otherChief->can('update', $report))->toBeFalse()
        ->and($this->world->users['director_general']->can('update', $report))->toBeFalse();

    CrossFixtures::signIn($otherChief)->put("/coverage-reports/{$report->ulid}", ['classification' => 'restricted', 'deployments' => []])->assertForbidden();
});

test('the full workflow: submit, return with a reason, resubmit, review; then the report is locked', function () {
    $report = coverageReport($this->chief, ['submit' => true]);
    $url = "/coverage-reports/{$report->ulid}";

    expect($report->status)->toBe(CoverageReportStatus::Submitted)
        ->and($report->submitted_by)->toBe($this->chief->id);
    Notification::assertSentTo($this->sd, CoverageReportSubmitted::class);
    Notification::assertSentTo($this->dsd, CoverageReportSubmitted::class);

    // An LGA chief cannot review; a reason is needed to return.
    CrossFixtures::signIn($this->chief)->post("{$url}/review")->assertForbidden();
    CrossFixtures::signIn($this->chief)->post("{$url}/return", ['reason' => 'Nope'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->post("{$url}/return", ['reason' => ''])->assertSessionHasErrors('reason');
    // Submitted is not editable.
    CrossFixtures::signIn($this->chief)->get("{$url}/edit")->assertForbidden();

    CrossFixtures::signIn($this->sd)->post("{$url}/return", ['reason' => 'Add the vigilante group deployment.'])->assertRedirect();
    expect($report->fresh()->status)->toBe(CoverageReportStatus::Returned);
    Notification::assertSentTo($this->chief, CoverageReportReturned::class);

    $fixed = coveragePayload();
    unset($fixed['lga'], $fixed['period_type'], $fixed['period_start'], $fixed['period_end']);
    $fixed['deployments'][] = ['agency' => $this->agency->ulid, 'personnel_count' => 10, 'patrol_frequency' => 'weekly', 'communities' => [$this->communities[1]->ulid]];
    CrossFixtures::signIn($this->chief)->put($url, [...$fixed, 'submit' => true])->assertSessionHasNoErrors();
    expect($report->fresh()->status)->toBe(CoverageReportStatus::Submitted);

    CrossFixtures::signIn($this->dsd)->post("{$url}/review", ['comment' => 'Thank you.'])->assertRedirect();
    $report->refresh();
    expect($report->status)->toBe(CoverageReportStatus::Reviewed)
        ->and($report->reviewed_by)->toBe($this->dsd->id);
    Notification::assertSentTo($this->chief, CoverageReportReviewed::class);

    // Locked: every edit is refused over HTTP, by everyone.
    foreach ([$this->chief, $this->sd, $this->dsd] as $user) {
        CrossFixtures::signIn($user)->get("{$url}/edit")->assertForbidden();
        CrossFixtures::signIn($user)->put($url, [...$fixed, 'summary' => 'Changed'])->assertForbidden();
        CrossFixtures::signIn($user)->post("{$url}/return", ['reason' => 'Reopen please'])->assertForbidden();
        CrossFixtures::signIn($user)->post("{$url}/review")->assertForbidden();
        CrossFixtures::signIn($user)->delete($url)->assertForbidden();
    }

    // …and by the database.
    CrossFixtures::system();
    expect(fn () => DB::transaction(fn () => $report->forceFill(['summary' => 'Tampered'])->save()))->toThrow(QueryException::class, 'locked')
        ->and(fn () => DB::transaction(fn () => CoverageReportDeployment::query()->where('coverage_report_id', $report->id)->update(['personnel_count' => 0])))->toThrow(QueryException::class, 'locked');

    // The timeline and the versions are in the activity log.
    $events = Activity::query()->where('subject_type', $report->getMorphClass())->where('subject_id', $report->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toContain('coverage_report_submitted', 'coverage_report_returned', 'coverage_report_reviewed');
    $reviewed = Activity::query()->where('event', 'coverage_report_reviewed')->where('subject_id', $report->id)->sole();
    expect($reviewed->properties['version']['deployments'])->toHaveCount(2)
        ->and($reviewed->properties['version']['summary'])->toBe('Police cover the town centre; the riverside is thin.');
    $submissions = Activity::query()->where('event', 'coverage_report_submitted')->where('subject_id', $report->id)->get();
    expect($submissions)->toHaveCount(2)
        ->and($submissions[0]->properties['version']['deployments'])->toHaveCount(1)
        ->and($submissions[1]->properties['resubmission'])->toBeTrue();

    CrossFixtures::signIn($this->chief)->get($url)->assertInertia(fn (Assert $page) => $page->component('coverage-reports/show')
        ->where('can.update', false)->where('can.review', false)
        ->has('timeline', 5));
});

test('only the creator discards, and only drafts', function () {
    $draft = coverageReport($this->chief);

    CrossFixtures::signIn($this->sd)->delete("/coverage-reports/{$draft->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->chief)->delete("/coverage-reports/{$draft->ulid}")->assertRedirect('/coverage-reports');
    expect($draft->fresh()->trashed())->toBeTrue();

    $submitted = coverageReport($this->chief, ['submit' => true]);
    CrossFixtures::signIn($this->chief)->delete("/coverage-reports/{$submitted->ulid}")->assertForbidden();
    expect($submitted->fresh()->trashed())->toBeFalse();
});

test('editing replaces the deployment list: rows kept, added and removed', function () {
    $report = coverageReport($this->chief);
    CrossFixtures::system();
    $kept = CoverageReportDeployment::query()->sole();

    CrossFixtures::signIn($this->chief)->put("/coverage-reports/{$report->ulid}", [
        'classification' => 'restricted',
        'deployments' => [
            ['ulid' => $kept->ulid, 'agency' => $this->agency->ulid, 'personnel_count' => 55, 'patrol_frequency' => 'continuous', 'communities' => [$this->communities[2]->ulid]],
            ['agency' => $this->agency->ulid, 'personnel_count' => 5, 'patrol_frequency' => 'weekly', 'communities' => []],
        ],
    ])->assertSessionHasNoErrors();

    CrossFixtures::system();
    expect(CoverageReportDeployment::query()->where('coverage_report_id', $report->id)->count())->toBe(2)
        ->and($kept->fresh()->personnel_count)->toBe(55)
        ->and($kept->fresh()->communities->pluck('id')->all())->toBe([$this->communities[2]->id]);

    CrossFixtures::signIn($this->chief)->put("/coverage-reports/{$report->ulid}", ['classification' => 'restricted', 'deployments' => []])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect(CoverageReportDeployment::query()->where('coverage_report_id', $report->id)->count())->toBe(0)
        ->and(CoverageReportDeployment::withTrashed()->where('coverage_report_id', $report->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Computed figures
|--------------------------------------------------------------------------
*/

test('totals, uncovered communities and comparison with the previous report', function () {
    $august = coverageReport($this->chief, [
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'overall_rating' => 'adequate', 'gaps_identified' => null,
        'deployments' => [['agency' => $this->agency->ulid, 'personnel_count' => 60, 'patrol_frequency' => 'daily', 'communities' => [$this->communities[0]->ulid, $this->communities[1]->ulid]]],
        'submit' => true,
    ]);
    $army = SecurityAgency::factory()->create(['name' => 'Nigerian Army']);
    $september = coverageReport($this->chief, [
        'overall_rating' => 'inadequate',
        'deployments' => [
            ['agency' => $this->agency->ulid, 'personnel_count' => 30, 'vehicles_count' => 3, 'patrol_frequency' => 'daily', 'communities' => [$this->communities[0]->ulid]],
            ['agency' => $army->ulid, 'personnel_count' => 12, 'vehicles_count' => 2, 'patrol_frequency' => 'weekly', 'communities' => [$this->communities[0]->ulid]],
        ],
    ]);

    $analysis = app(CoverageAnalysis::class);
    [$totals, $uncovered, $comparison] = app(JurisdictionContext::class)->actingAs($this->chief, fn () => [
        $analysis->totals($september),
        $analysis->uncovered($september)->pluck('name')->all(),
        $analysis->comparison($september),
    ]);

    expect($totals['personnel'])->toBe(42)
        ->and($totals['vehicles'])->toBe(5)
        ->and(array_column(array_column($totals['by_agency'], 'agency'), 'name'))->toBe(['Nigeria Police Force', 'Nigerian Army'])
        ->and($uncovered)->toBe(['Bariga', 'Coker'])
        ->and($comparison['report']['ulid'])->toBe($august->ulid)
        ->and($comparison['personnel_change'])->toBe(-18)
        ->and($comparison['rating'])->toBe(['previous' => 'Adequate', 'current' => 'Inadequate', 'change' => 'worsened'])
        ->and(array_column($comparison['newly_uncovered'], 'name'))->toBe(['Bariga']);

    CrossFixtures::signIn($this->chief)->get("/coverage-reports/{$september->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('totals.personnel', 42)->has('uncovered', 2)->where('comparison.personnel_change', -18)->where('communityCount', 3));
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

test('submission notifies exactly the state leads; a critical rating also alerts the DG and covering DDGs', function () {
    $otherSd = User::factory()->stateDirector($this->world->states['B1'])->create();

    coverageReport($this->chief, ['submit' => true]);

    Notification::assertSentTo([$this->sd, $this->dsd], CoverageReportSubmitted::class);
    Notification::assertNotSentTo([$otherSd, $this->chief, $this->world->users['director_general']], CoverageReportSubmitted::class);
    Notification::assertNothingSentTo($this->world->users['director_general']);

    coverageReport($this->chief, ['period_type' => 'ad_hoc', 'overall_rating' => 'critical', 'submit' => true]);

    Notification::assertSentTo(
        [$this->world->users['director_general'], $this->world->users['ddg_national'], $this->world->users['ddg_zone_a'], $this->world->users['ddg_zones_a_b']],
        CoverageCriticalRating::class,
        fn (CoverageCriticalRating $n) => $n->severity()->value === 'critical',
    );
    Notification::assertNotSentTo([$this->world->users['ddg_zone_b'], $this->sd, $otherSd], CoverageCriticalRating::class);
});

/*
|--------------------------------------------------------------------------
| Sidebar badge and dashboards
|--------------------------------------------------------------------------
*/

test('the sidebar badge counts reports awaiting the reviewer', function () {
    coverageReport($this->chief, ['submit' => true]);

    CrossFixtures::signIn($this->sd)->get('/coverage-reports')->assertInertia(fn (Assert $page) => $page->where('badges.coverageReview', 1)->where('reports.data.0.can_review', true));
    CrossFixtures::signIn($this->chief)->get('/coverage-reports')->assertInertia(fn (Assert $page) => $page->where('badges.coverageReview', 0));
    CrossFixtures::signIn(User::factory()->stateDirector($this->world->states['B1'])->create())->get('/coverage-reports')->assertInertia(fn (Assert $page) => $page->where('badges.coverageReview', 0));
});

test('dashboards show coverage figures within jurisdiction and classification', function () {
    // Current period (October 2026): A1a draft; A1b secret, submitted.
    coverageReport($this->chief, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    $secret = CoverageReport::factory()->in($this->world->lgas['A1b'])->forMonth(now()->toImmutable())->classified(Classification::Secret)->create();
    // Last completed period (September): A1a submitted critical; A2 reviewed.
    coverageReport($this->chief, ['overall_rating' => 'critical', 'submit' => true]);
    CoverageReport::factory()->in($this->world->lgas['A2'])->forMonth(now()->subMonthNoOverflow()->toImmutable())->create([
        'status' => CoverageReportStatus::Submitted, 'submitted_at' => now(), 'overall_rating' => CoverageRating::Adequate, 'summary' => 'Fine.',
    ]);

    CrossFixtures::signIn($this->chief)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('coverage.period', 'October 2026')
        ->where('coverage.due', '2026-11-05')
        ->where('coverage.current.status.value', 'draft')
        ->where('coverage.lastRating.value', 'critical')
        ->where('coverage.uncovered.count', 2));

    // State Director: every LGA of the state; the secret one is visible to them.
    $row = fn (array $lgas, string $key) => collect($lgas)->firstWhere('lga.ulid', $this->world->lgas[$key]->ulid);
    CrossFixtures::signIn($this->sd)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('coverage.lgas', 2)
        ->where('coverage.lgas', fn ($lgas) => $row($lgas->all(), 'A1a')['status']['value'] === 'draft'
            && $row($lgas->all(), 'A1b')['status']['value'] === 'draft'
            && $row($lgas->all(), 'A1b')['report'] === $secret->ulid));
    // The Deputy cannot see the secret report: it counts as missing.
    CrossFixtures::signIn($this->dsd)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('coverage.lgas', fn ($lgas) => $row($lgas->all(), 'A1b')['status']['value'] === 'missing'
            && $row($lgas->all(), 'A1b')['report'] === null));

    // National: compliance for September by state; critical A1a listed.
    CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('coverage.period', 'September 2026')
        ->where('coverage.compliance', fn ($rows) => collect($rows)->firstWhere('state.name', $this->world->states['A1']->name)['percent'] === 50
            && collect($rows)->firstWhere('state.name', $this->world->states['A2']->name)['percent'] === 100
            && collect($rows)->firstWhere('state.name', $this->world->states['B1']->name)['percent'] === 0)
        ->where('coverage.poorlyCovered.0.lgas.0.lga', $this->lga->name));
    // A zone B DDG sees only zone B.
    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('coverage.compliance', 1)
        ->where('coverage.poorlyCovered', []));
});

test('an LGA chief sees who at state level returned their report', function () {
    $report = coverageReport($this->chief, ['submit' => true]);
    CrossFixtures::signIn($this->sd)->post("/coverage-reports/{$report->ulid}/return", ['reason' => 'Add the vehicles.'])->assertRedirect();

    CrossFixtures::signIn($this->chief)->get("/coverage-reports/{$report->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('report.returned_by.name', $this->sd->name)
        ->where('timeline.2.label', 'Returned for changes')
        ->where('timeline.2.by', $this->sd->name));
});
