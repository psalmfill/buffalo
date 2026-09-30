<?php

use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Notifications\CoverageReportMissing;
use App\Notifications\CoverageReportsMissingSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    Notification::fake();
    $this->world = JurisdictionWorld::build();
    // A1b: a chief of its own. A1a: lga_chief. A2, B1: no chiefs.
    $this->chiefA1b = User::factory()->lgaChief($this->world->lgas['A1b'])->create();
    $this->sdB1 = User::factory()->stateDirector($this->world->states['B1'])->create();

    // September 2026 reports: A1a submitted; A2 a draft only (still missing).
    CoverageReport::factory()->in($this->world->lgas['A1a'])->forMonth(CarbonImmutable::parse('2026-09-01'))->create([
        'status' => CoverageReportStatus::Submitted, 'submitted_at' => '2026-10-02 09:00', 'overall_rating' => CoverageRating::Adequate, 'summary' => 'Fine.',
    ]);
    CoverageReport::factory()->in($this->world->lgas['A2'])->forMonth(CarbonImmutable::parse('2026-09-01'))->create();
});

test('nothing is sent within the grace days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00'));

    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('after the grace days LGA chiefs and State Directors of missing LGAs are told, once per period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00'));

    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();
    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->chiefA1b, CoverageReportMissing::class, 1);
    Notification::assertNotSentTo($this->world->users['lga_chief'], CoverageReportMissing::class);

    Notification::assertSentToTimes($this->world->users['state_director'], CoverageReportsMissingSummary::class, 1);
    Notification::assertSentTo($this->world->users['state_director'], CoverageReportsMissingSummary::class,
        fn (CoverageReportsMissingSummary $n) => $n->lgas === [$this->world->lgas['A1b']->name] && $n->period === 'September 2026');
    Notification::assertSentTo($this->sdB1, CoverageReportsMissingSummary::class,
        fn (CoverageReportsMissingSummary $n) => $n->lgas === [$this->world->lgas['B1']->name]);
    Notification::assertNotSentTo($this->world->users['deputy_state_director'], CoverageReportsMissingSummary::class);
    Notification::assertNotSentTo($this->world->users['director_general'], CoverageReportsMissingSummary::class);

    // The next period sends again.
    $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00'));
    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->chiefA1b, CoverageReportMissing::class, 2);
});

test('the expected period and grace days are configurable', function () {
    config()->set('buffalo.coverage.expected_period', 'weekly');
    config()->set('buffalo.coverage.grace_days', 1);
    // Tuesday 2026-10-06: last week ended Sunday 4th; due Monday 5th.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00'));

    $this->artisan('buffalo:coverage-reminders')->assertSuccessful();

    Notification::assertSentTo($this->world->users['lga_chief'], CoverageReportMissing::class, fn (CoverageReportMissing $n) => $n->period === 'Week of 28 Sep 2026');
});
