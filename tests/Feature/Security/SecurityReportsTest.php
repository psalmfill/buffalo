<?php

use App\Enums\CoveragePeriodType;
use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Notifications\CoverageReportMissing;
use App\Notifications\CoverageReportSubmitted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11G.2: coverage reports become security reports. The written report
| (the `summary` column, labelled "Report") is all that is needed to submit;
| everything else is optional; ad hoc reports are unlimited; reminders count
| any report submitted during the period.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
});

/** @param array<string, mixed> $values */
function securityReportPayload(array $values = []): array
{
    return [
        'lga' => test()->lga->ulid,
        'summary' => '<p>Calm week in the LGA.</p>',
        'classification' => 'restricted',
        'deployments' => [],
        ...$values,
    ];
}

test('an LGA chief submits a report with only the report text: an ad hoc report dated today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));

    CrossFixtures::signIn($this->chief)->post('/security-reports', [
        'lga' => $this->lga->ulid, 'summary' => '<p>Two checkpoints unmanned at night.</p>', 'classification' => 'restricted', 'deployments' => [], 'submit' => true,
    ])->assertSessionHasNoErrors();

    $report = CoverageReport::query()->latest('id')->firstOrFail();
    expect($report->status)->toBe(CoverageReportStatus::Submitted)
        ->and($report->period_type)->toBe(CoveragePeriodType::AdHoc)
        ->and($report->period_start->toDateString())->toBe('2026-11-04')
        ->and($report->period_end->toDateString())->toBe('2026-11-04')
        ->and($report->report_date->toDateString())->toBe('2026-11-04')
        ->and($report->overall_rating)->toBeNull()
        ->and($report->title)->toBeNull()
        ->and($report->displayTitle())->toBe("Security report — {$this->lga->name} — 4 Nov 2026");
});

test('the report text may run to 50,000 characters of plain text, not more', function () {
    $long = '<p>'.str_repeat('a', 50000).'</p>';
    $tooLong = '<p>'.str_repeat('a', 50001).'</p>';

    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['summary' => $long, 'submit' => true]))->assertSessionHasNoErrors();
    expect(mb_strlen((string) CoverageReport::query()->latest('id')->firstOrFail()->summary_text))->toBe(50000);

    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['summary' => $tooLong]))->assertSessionHasErrors('summary');
    expect(config('buffalo.security_reports.max_characters'))->toBe(50000);
});

test('every other field is optional, and saved when given', function () {
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload([
        'title' => 'Market fire aftermath',
        'report_date' => '2026-10-20',
        'overall_rating' => 'partial',
        'gaps_identified' => '<p>No night patrols.</p>',
        'recommendations' => '<p>Add a police post.</p>',
    ]))->assertSessionHasNoErrors();

    $report = CoverageReport::query()->latest('id')->firstOrFail();
    expect($report->title)->toBe('Market fire aftermath')
        ->and($report->displayTitle())->toBe('Market fire aftermath')
        ->and($report->report_date->toDateString())->toBe('2026-10-20')
        ->and($report->period_start->toDateString())->toBe('2026-10-20')
        ->and($report->overall_rating?->value)->toBe('partial');

    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['title' => str_repeat('t', 201)]))->assertSessionHasErrors('title');
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['report_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('report_date');
});

test('ad hoc reports are unlimited per LGA; weekly, monthly and quarterly stay one per period', function () {
    foreach (range(1, 3) as $i) {
        CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['period_type' => 'ad_hoc']))->assertSessionHasNoErrors();
    }
    expect(CoverageReport::query()->where('lga_id', $this->lga->id)->where('period_type', 'ad_hoc')->count())->toBe(3);

    $september = ['period_type' => 'monthly', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'];
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload($september))->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload($september))->assertSessionHasErrors('period_start');
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['period_type' => 'monthly', 'period_start' => '2026-09-02', 'period_end' => '2026-09-30']))->assertSessionHasErrors('period_start');
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['period_type' => 'weekly']))->assertSessionHasErrors('period_start');
});

test('the period can be chosen later while the report is a draft, and stays unique', function () {
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['period_type' => 'monthly', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']))->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload())->assertSessionHasNoErrors();
    $draft = CoverageReport::query()->latest('id')->firstOrFail();

    // August is taken; September is free; keeping its own period is fine.
    CrossFixtures::signIn($this->chief)->put("/security-reports/{$draft->ulid}", securityReportPayload(['lga' => null, 'period_type' => 'monthly', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']))
        ->assertSessionHasErrors('period_start');
    $payload = securityReportPayload(['period_type' => 'monthly', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    unset($payload['lga']);
    CrossFixtures::signIn($this->chief)->put("/security-reports/{$draft->ulid}", $payload)->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->chief)->put("/security-reports/{$draft->ulid}", $payload)->assertSessionHasNoErrors();

    expect($draft->fresh()->period_type)->toBe(CoveragePeriodType::Monthly);
});

test('reminders count any report submitted during the period, ad hoc included', function () {
    // October 2026 (monthly): A1a submits an ad hoc report on 12 October.
    CoverageReport::factory()->in($this->lga)->create([
        'period_type' => 'ad_hoc', 'period_start' => '2026-10-12', 'period_end' => '2026-10-12', 'report_date' => '2026-10-12',
        'status' => CoverageReportStatus::Submitted, 'submitted_at' => '2026-10-12 09:00', 'summary' => '<p>Report.</p>',
    ]);
    // A1b only has a draft written in October.
    CoverageReport::factory()->in($this->world->lgas['A1b'])->create([
        'period_type' => 'ad_hoc', 'period_start' => '2026-10-20', 'period_end' => '2026-10-20', 'summary' => '<p>Draft.</p>',
    ]);
    $chiefA1b = User::factory()->lgaChief($this->world->lgas['A1b'])->create();

    $this->travelTo(CarbonImmutable::parse('2026-11-07 08:00'));
    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();

    Notification::assertNotSentTo($this->chief, CoverageReportMissing::class);
    Notification::assertSentTo($chiefA1b, CoverageReportMissing::class, fn (CoverageReportMissing $n) => $n->period === 'October 2026');
});

test('the LGA dashboard counts an ad hoc report submitted this period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['submit' => true]))->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->chief)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('coverage.period', 'November 2026')
        ->where('coverage.current.status.value', 'submitted'));
});

test('computed figures appear only when deployments were entered', function () {
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload())->assertSessionHasNoErrors();
    $report = CoverageReport::query()->latest('id')->firstOrFail();

    CrossFixtures::signIn($this->chief)->get("/security-reports/{$report->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('coverage-reports/show')
        ->where('totals', null)
        ->where('comparison', null)
        ->where('uncovered', [])
        ->where('report.display_title', $report->displayTitle()));
});

test('existing report text is kept: the summary column is the report', function () {
    $old = CoverageReport::factory()->in($this->lga)->create(['summary' => '<p>Written before Phase 11G.</p>', 'created_by' => $this->chief->id]);

    CrossFixtures::signIn($this->chief)->get("/security-reports/{$old->ulid}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('coverage-reports/form')
        ->where('report.summary', '<p>Written before Phase 11G.</p>'));
});

test('old coverage report links redirect permanently to security reports', function () {
    $report = CoverageReport::factory()->in($this->lga)->create();

    CrossFixtures::signIn($this->chief)->get('/coverage-reports')->assertStatus(301)->assertRedirect('/security-reports');
    CrossFixtures::signIn($this->chief)->get("/coverage-reports/{$report->ulid}")->assertStatus(301)->assertRedirect("/security-reports/{$report->ulid}");
});

test('the interface says security report', function () {
    CrossFixtures::signIn($this->chief)->post('/security-reports', securityReportPayload(['submit' => true]))
        ->assertSessionHas('toast', fn (array $toast) => $toast['message'] === 'Security report submitted.');

    Notification::assertSentTo($this->sd, CoverageReportSubmitted::class, fn ($n) => $n->title($this->sd) === 'Security report submitted');
});
