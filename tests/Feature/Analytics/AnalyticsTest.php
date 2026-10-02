<?php

use App\Enums\AccessAction;
use App\Enums\CasualtyOutcome;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\OccurrencePrecision;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatStatus;
use App\Models\AnalyticsExport;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\RecordAccessLog;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\CasualtyAnalytics;
use App\Services\Analytics\IncidentAnalytics;
use App\Services\Analytics\ResponseAnalytics;
use App\Services\Analytics\ThreatAnalytics;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| A fixed fixture set (world: A1 with LGAs A1a and A1b, A2, B1):
| - counted incidents in A1a, A1b, A2 and B1 within the last 30 days;
| - a draft and an invalid report in A1a (never counted);
| - a secret A1a incident by someone else, and one created by the DSD;
| - one incident 60 days ago (outside "30d", inside the comparison).
*/
beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'Africa/Lagos'));
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chief = $this->world->users['lga_chief'];
    $this->dg = $this->world->users['director_general'];
    $this->kidnapping = IncidentType::factory()->create(['name' => 'Kidnapping', 'code' => 'kidnapping', 'category' => 'crime']);
    $this->robbery = IncidentType::factory()->create(['name' => 'Armed robbery', 'code' => 'robbery', 'category' => 'crime']);

    $at = fn (string $ago) => CarbonImmutable::now()->sub($ago);
    $make = function (string $lga, array $attrs = [], IncidentStatus $status = IncidentStatus::Verified, array $columns = []) {
        CrossFixtures::system();

        return W::at(W::draft($this->world->lgas[$lga], $attrs['created_by_user'] ?? $this->chief, collect($attrs)->except('created_by_user')->all()), $status, $columns);
    };

    $this->counted = [
        $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('2 days'), 'severity' => 'high']),
        // Closed incidents are locked: their response times are set as they close.
        $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('3 days'), 'severity' => 'high'], IncidentStatus::Closed,
            ['submitted_at' => $at('3 days')->addHours(2), 'verified_at' => $at('3 days')->addHours(2)->addMinutes(480)]),
        $make('A1b', ['incident_type_id' => $this->robbery->id, 'occurred_at' => $at('10 days'), 'severity' => 'low']),
        $make('A2', ['incident_type_id' => $this->robbery->id, 'occurred_at' => $at('5 days')]),
        $make('B1', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('6 days')]),
    ];
    $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('1 day')], IncidentStatus::Draft);
    $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('1 day')], IncidentStatus::Invalid);
    $this->secretOther = $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('4 days'), 'classification' => Classification::Secret, 'created_by_user' => CrossFixtures::chiefOf($this->world->lgas['A1b'])]);
    $this->secretOwn = $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('4 days'), 'classification' => Classification::Secret, 'created_by_user' => $this->dsd]);
    $this->old = $make('A1a', ['incident_type_id' => $this->kidnapping->id, 'occurred_at' => $at('45 days')]);
});

/** Runs $callback as $user (the viewer's scopes). */
function asViewer(User $user, Closure $callback): mixed
{
    return app(JurisdictionContext::class)->actingAs($user, $callback);
}

/** Direct scoped count of counted incidents in [start, end). */
function directCount(User $user, CarbonImmutable $start, CarbonImmutable $end): int
{
    return asViewer($user, fn () => Incident::query()
        ->whereNotIn('status', ['draft', 'invalid'])
        ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
        ->count());
}

/*
|--------------------------------------------------------------------------
| Figures equal direct scoped counts
|--------------------------------------------------------------------------
*/

test('incident figures equal direct scoped counts per role; drafts, invalid and above-clearance excluded', function () {
    $expected = ['director_general' => 7, 'state_director' => 5, 'deputy_state_director' => 4, 'lga_chief' => 2];

    foreach ($expected as $role => $count) {
        $user = $this->world->users[$role];
        [$trend, $byType, $byGeo] = asViewer($user, function () use ($user) {
            $f = AnalyticsFilters::from(['period' => '30d'], $user);
            $a = app(IncidentAnalytics::class);

            return [$a->trend($user, $f), $a->byType($user, $f), $a->byGeography($user, $f)];
        });
        $direct = directCount($user, CarbonImmutable::now()->startOfDay()->subDays(29), CarbonImmutable::now()->startOfDay()->addDay());

        expect(array_sum($trend['series'][0]['data']))->toBe($count, "trend for {$role}")
            ->and($direct)->toBe($count, "direct for {$role}")
            ->and(array_sum(array_column($byType['rows'], 'count')))->toBe($count)
            ->and(array_sum(array_column($byGeo['rows'], 'count')))->toBe($count);
    }

    // The DSD's figure includes the secret incident they created (creator rule), not the other.
    expect(asViewer($this->dsd, fn () => app(IncidentAnalytics::class)->byType($this->dsd, AnalyticsFilters::from([], $this->dsd))['rows']))
        ->toContain(['label' => 'Kidnapping', 'count' => 3, 'comparison' => null, 'change' => null, 'change_pct' => null]);
});

test('comparison, change and geography one level below the selection', function () {
    $rows = asViewer($this->sd, function () {
        $f = AnalyticsFilters::from(['period' => '30d', 'compare' => 'previous'], $this->sd);

        return app(IncidentAnalytics::class)->byType($this->sd, $f)['rows'];
    });
    $kidnapping = collect($rows)->firstWhere('label', 'Kidnapping');
    expect($kidnapping)->toMatchArray(['count' => 4, 'comparison' => 1, 'change' => 3, 'change_pct' => 300.0]);

    // National: by state; a state: its LGAs (zeros included), with rates where population exists.
    DB::table('lgas')->where('id', $this->world->lgas['A1a']->id)->update(['population_estimate' => 200000]);
    $national = asViewer($this->dg, fn () => app(IncidentAnalytics::class)->byGeography($this->dg, AnalyticsFilters::from([], $this->dg)));
    $state = asViewer($this->dg, fn () => app(IncidentAnalytics::class)->byGeography($this->dg, AnalyticsFilters::from(['state' => $this->world->states['A1']->ulid], $this->dg)));
    expect($national['title'])->toBe('By state')
        ->and(collect($national['rows'])->pluck('count', 'label')->all())->toMatchArray([$this->world->states['A1']->name => 5, $this->world->states['B1']->name => 1])
        ->and($state['title'])->toBe('By LGA')
        ->and(collect($state['rows'])->firstWhere('label', $this->world->lgas['A1a']->name)['count'])->toBe(4)
        ->and(collect($state['rows'])->firstWhere('label', $this->world->lgas['A1a']->name)['rate'])->toBe(round(4 / 200000 * 100000 / (30 / 365.25), 2))
        ->and($state['link']['query']['layers'])->toBe('lga_choropleth');
});

test('time patterns use exact times only and report the excluded', function () {
    DB::table('incidents')->where('id', $this->counted[0]->id)->update(['occurred_at_precision' => OccurrencePrecision::DateOnly->value]);
    $chart = asViewer($this->sd, fn () => app(IncidentAnalytics::class)->timePatterns($this->sd, AnalyticsFilters::from([], $this->sd)));

    expect(array_sum(array_column($chart['rows'], 'total')))->toBe(4)
        ->and($chart['note'])->toContain('1 with an approximate time or a date only');
});

test('casualties count only counted, visible incidents', function () {
    $add = fn (Incident $i, string $outcome, int $people) => DB::table('incident_casualties')->insert([
        'ulid' => (string) str()->ulid(), 'incident_id' => $i->id, 'state_id' => $i->state_id, 'lga_id' => $i->lga_id,
        'outcome' => $outcome, 'group' => 'civilian', 'adult_male' => $people, 'adult_female' => 0, 'child_male' => 0, 'child_female' => 0, 'unknown' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $add($this->counted[0], 'killed', 2);
    $add($this->counted[0], 'abducted', 5);
    $add($this->counted[0], 'rescued', 3);
    $add($this->secretOther, 'killed', 7);
    $add($this->counted[4], 'killed', 11);   // B1: other state

    $sd = asViewer($this->sd, fn () => app(CasualtyAnalytics::class)->charts($this->sd, AnalyticsFilters::from([], $this->sd)));
    $dsd = asViewer($this->dsd, fn () => app(CasualtyAnalytics::class)->topTypes($this->dsd, AnalyticsFilters::from([], $this->dsd), CasualtyOutcome::Killed));
    $byKey = collect($sd)->keyBy('key');

    expect(collect($byKey['top_types_killed']['rows'])->firstWhere('label', 'Kidnapping')['people'])->toBe(9)
        ->and(collect($dsd['rows'])->firstWhere('label', 'Kidnapping')['people'])->toBe(2)
        ->and(array_sum(array_column($byKey['abducted_rescued']['rows'], 'abducted')))->toBe(5)
        ->and(array_sum(array_column($byKey['abducted_rescued']['rows'], 'rescued')))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Response times
|--------------------------------------------------------------------------
*/

test('response medians and percentiles, with date-only incidents excluded from reporting delay', function () {
    // Reporting delays 1 h, 2 h, 3 h (10 h out of reach); verification 30 min (critical: within 60), 8 h (high: beyond 6 h).
    $set = function (Incident $i, int $delayHours, ?int $verifyMinutes, string $severity, string $precision = 'exact') {
        $submitted = CarbonImmutable::parse($i->occurred_at)->addHours($delayHours);
        DB::table('incidents')->where('id', $i->id)->update([
            'submitted_at' => $submitted, 'severity' => $severity, 'occurred_at_precision' => $precision,
            'verified_at' => $verifyMinutes === null ? null : $submitted->addMinutes($verifyMinutes),
        ]);
    };
    $set($this->counted[0], 1, 30, 'critical');
    // counted[1] (closed): 2 h delay, verified 8 h later, high (set in beforeEach).
    $set($this->counted[2], 3, null, 'low');
    $set($this->counted[3], 10, null, 'low');            // A2: not in the SD's reach
    $set($this->secretOther, 100, null, 'low', 'date_only');
    $set($this->secretOwn, 4, null, 'low', 'date_only');

    $summary = asViewer($this->sd, fn () => app(ResponseAnalytics::class)->summary($this->sd, AnalyticsFilters::from([], $this->sd)));
    $delay = $summary['rows'][0];
    expect($delay['count'])->toBe(3)                     // date-only excluded
        ->and($delay['median'])->toBe(2.0)
        ->and($delay['p90'])->toBe(2.8)                  // percentile_cont(0.9) of 1, 2, 3 h
        ->and($summary['note'])->toContain('excludes 2 incidents');

    $verification = collect(asViewer($this->sd, fn () => app(ResponseAnalytics::class)->verificationBySeverity($this->sd, AnalyticsFilters::from([], $this->sd)))['rows'])->keyBy('label');
    expect($verification['Critical'])->toMatchArray(['count' => 1, 'within' => 1, 'within_pct' => 100.0, 'median' => 0.5])
        ->and($verification['High'])->toMatchArray(['count' => 1, 'within' => 0, 'within_pct' => 0.0]);

    $byPlace = collect(asViewer($this->sd, fn () => app(ResponseAnalytics::class)->byPlace($this->sd, AnalyticsFilters::from([], $this->sd)))['rows'])->keyBy('label');
    expect($byPlace[$this->world->lgas['A1a']->name])->toMatchArray(['verified' => 2, 'within_pct' => 50.0]);
});

/*
|--------------------------------------------------------------------------
| Threats
|--------------------------------------------------------------------------
*/

test('materialisation rate by credibility counts reports that reached a final status', function () {
    $lga = $this->world->lgas['A1a'];
    $threat = fn (ThreatCredibility $c, ThreatStatus $s) => T::threat($lga, ['credibility' => $c, 'status' => $s, 'status_note' => $s === ThreatStatus::Active ? null : 'Outcome recorded.', 'created_at' => now()->subDays(3)]);
    $threat(ThreatCredibility::Probable, ThreatStatus::Materialised);
    $threat(ThreatCredibility::Probable, ThreatStatus::Materialised);
    $threat(ThreatCredibility::Probable, ThreatStatus::Mitigated);
    $threat(ThreatCredibility::Probable, ThreatStatus::Active);      // not final
    $threat(ThreatCredibility::Possible, ThreatStatus::Expired);
    T::threat($this->world->lgas['B1'], ['credibility' => ThreatCredibility::Possible, 'status' => ThreatStatus::Materialised, 'status_note' => 'Outcome recorded.', 'created_at' => now()->subDays(3)]);

    $rows = collect(asViewer($this->sd, fn () => app(ThreatAnalytics::class)->materialisation($this->sd, AnalyticsFilters::from([], $this->sd)))['rows'])->keyBy('label');

    expect($rows['Probable'])->toMatchArray(['final' => 3, 'materialised' => 2, 'rate' => 66.7, 'open' => 1])
        ->and($rows['Possible'])->toMatchArray(['final' => 1, 'materialised' => 0, 'rate' => 0.0])
        ->and($rows['Confirmed']['rate'])->toBeNull()
        ->and(ThreatAnalytics::rate(1, 3))->toBe(33.3);
});

/*
|--------------------------------------------------------------------------
| Periods
|--------------------------------------------------------------------------
*/

test('periods, comparisons and granularity, including quarter boundaries in Africa/Lagos', function () {
    // 23:30 UTC on 30 June is 00:30 on 1 July in Lagos: already the third quarter.
    $now = CarbonImmutable::parse('2026-06-30 23:30', 'UTC');
    $f = fn (array $input) => AnalyticsFilters::from($input, $this->sd, $now);

    expect($f(['period' => 'this_quarter'])->start->toDateString())->toBe('2026-07-01')
        ->and($f(['period' => 'this_quarter'])->end->toDateString())->toBe('2026-07-02')
        ->and($f(['period' => 'last_quarter'])->start->toDateString())->toBe('2026-04-01')
        ->and($f(['period' => 'last_quarter'])->end->toDateString())->toBe('2026-07-01')
        ->and(array_map(fn ($d) => $d->toDateString(), $f(['period' => 'last_quarter', 'compare' => 'previous'])->comparison()))->toBe(['2026-01-01', '2026-04-01'])
        ->and(array_map(fn ($d) => $d->toDateString(), $f(['period' => 'last_quarter', 'compare' => 'last_year'])->comparison()))->toBe(['2025-04-01', '2025-07-01'])
        ->and($f(['period' => 'last_quarter'])->granularity)->toBe('week')
        ->and($f(['period' => '7d'])->start->toDateString())->toBe('2026-06-25')
        ->and(array_map(fn ($d) => $d->toDateString(), $f(['period' => '7d', 'compare' => 'previous'])->comparison()))->toBe(['2026-06-18', '2026-06-25'])
        // 1 Jan – 1 Jul is 182 days: weekly; a full year is monthly.
        ->and($f(['period' => 'this_year'])->granularity)->toBe('week')
        ->and($f(['period' => 'custom', 'from' => '2025-07-01', 'to' => '2026-06-30'])->granularity)->toBe('month')
        ->and($f(['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-06-30'])->granularity)->toBe('quarter')
        ->and($f(['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-06-30', 'granularity' => 'day'])->granularity)->toBe('week')
        ->and($f(['period' => 'custom', 'from' => '2026-05-10', 'to' => '2026-05-01'])->preset)->toBe('30d')
        ->and($f(['period' => 'bogus'])->preset)->toBe('30d');

    $quarter = $f(['period' => 'last_quarter', 'granularity' => 'month']);
    expect(array_column($quarter->buckets(), 'label'))->toBe(['Apr 2026', 'May 2026', 'Jun 2026']);
    // A partial "this month" compares with the same days of last month.
    $june = AnalyticsFilters::from(['period' => 'this_month', 'compare' => 'previous'], $this->sd, CarbonImmutable::parse('2026-06-10 10:00', 'Africa/Lagos'));
    expect(array_map(fn ($d) => $d->toDateString(), $june->comparison()))->toBe(['2026-05-01', '2026-05-11']);
});

/*
|--------------------------------------------------------------------------
| Cache, exports, access
|--------------------------------------------------------------------------
*/

test('cached figures are keyed by scope signature and never shared across signatures', function () {
    $charts = fn (User $u) => asViewer($u, fn () => app(Analytics::class)->charts($u, app(Analytics::class)->area($u, 'incidents'), AnalyticsFilters::from([], $u)));
    $count = fn (array $c) => array_sum(collect($c)->firstWhere('key', 'trend')['series'][0]['data']);

    expect($count($charts($this->sd)))->toBe(5)
        ->and($count($charts($this->dsd)))->toBe(4)
        ->and($count($charts($this->chief)))->toBe(2)
        ->and($count($charts($this->sd)))->toBe(5);

    // A second DSD of the same state (same role and clearance) has their own cache: the creator rule.
    $otherDeputy = User::factory()->deputyStateDirector($this->world->states['A1'])->create();
    expect($count($charts($otherDeputy)))->toBe(3);
});

test('CSV and PNG exports are logged with markings, and the CSV is built through the viewer\'s scopes', function () {
    DB::table('incident_types')->where('id', $this->robbery->id)->update(['name' => '=HYPERLINK("x")']);

    $response = CrossFixtures::signIn($this->dsd)->get('/analytics/incidents/by_type/csv?period=30d');
    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('CONFIDENTIAL — aggregate figures')
        ->toContain('Generated for '.$this->dsd->name)
        ->toContain('Kidnapping,3')
        ->toContain("'=HYPERLINK");
    $export = AnalyticsExport::query()->sole();
    $log = RecordAccessLog::query()->where('subject_type', $export->getMorphClass())->where('subject_id', $export->id)->sole();
    expect($export->format)->toBe('csv')
        ->and($log->getAttribute('context'))->toBe('analytics')
        ->and((int) $log->getAttribute('user_id'))->toBe($this->dsd->id)
        ->and(DB::table('record_access_logs')->where('id', $log->id)->value('action'))->toBe(AccessAction::Export->value);

    CrossFixtures::signIn($this->sd)->postJson('/analytics/exports/png', ['area' => 'incidents', 'chart' => 'trend', 'filters' => ['period' => '30d']])
        ->assertOk()
        ->assertJson(['classification' => 'SECRET — aggregate figures'])
        ->assertJsonPath('generated', fn ($g) => str_starts_with($g, 'Generated for '.$this->sd->name));
    expect(RecordAccessLog::query()->where('action', 'export')->where('context', 'analytics')->count())->toBe(2)
        ->and(AnalyticsExport::query()->where('format', 'png')->count())->toBe(1);
});

test('the cross-jurisdiction tab is for national users with oversight only; super admin gets nothing', function () {
    CrossFixtures::signIn($this->sd)->get('/analytics?tab=access')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('analytics/index')
        ->where('tab', 'incidents')
        ->where('tabs', fn ($tabs) => ! collect($tabs)->pluck('key')->contains('access')));
    CrossFixtures::signIn($this->sd)->get('/analytics/access/access_summary/csv')->assertNotFound();
    CrossFixtures::signIn($this->dg)->get('/analytics?tab=access')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'access')
        ->where('charts.0.key', 'access_summary'));
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/analytics')->assertForbidden();
});

test('dashboards take a period, show KPI tiles with change, and link to analytics', function () {
    CrossFixtures::signIn($this->sd)->get('/dashboard?days=7')->assertInertia(fn (Assert $page) => $page
        ->where('period.days', 7)
        ->where('kpis.0.key', 'incidents')
        ->where('kpis.0.value', 4)
        ->where('kpis.0.href', fn ($href) => str_contains($href, 'tab=incidents') && str_contains($href, 'period=7d'))
        ->where('incidents.days', 7));
    CrossFixtures::signIn($this->sd)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('period.days', 30)
        ->where('kpis.0.value', 5)
        ->where('kpis.0.previous', 1)
        ->where('kpis.0.change_pct', 400));
    CrossFixtures::signIn($this->sd)->get('/dashboard?days=13')->assertInertia(fn (Assert $page) => $page->where('period.days', 30));
});
