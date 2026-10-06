<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly reminder to LGA chiefs whose LGA profile is due for review (Phase 2B).
Schedule::command('buffalo:profile-review-reminders')->weeklyOn(1, '07:00');

// Daily reminder of overdue person of interest reviews (Phase 3B).
Schedule::command('buffalo:poi-review-reminders')->dailyAt('07:00');
Schedule::command('buffalo:flashpoint-review-reminders')->dailyAt('07:10');
Schedule::command('buffalo:expire-threat-reports')->everyFifteenMinutes();
Schedule::command('buffalo:threat-digest')->dailyAt('07:00');
Schedule::command('buffalo:suggest-blackspots')->dailyAt('02:00')->withoutOverlapping();

// Phase 7A — Dissemination
Schedule::command('buffalo:process-disseminations')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('buffalo:check-dissemination-gaps')->dailyAt('07:20');

// Cross-jurisdiction access (Phase 3C).
Schedule::command('buffalo:escalate-access-requests')->everyFiveMinutes();
Schedule::command('buffalo:expire-access-grants')->everyFifteenMinutes();
Schedule::command('buffalo:match-digest')->dailyAt('06:30');

// Security agencies (Phase 4A): end temporary formations past their dates.
Schedule::command('buffalo:expire-temporary-formations')->dailyAt('00:30');

// Coverage reports (Phase 4A): overdue reminders, once per period.
Schedule::command('buffalo:coverage-reminders')->dailyAt('08:00');

// Incidents past their verification deadline (Phase 5C).
Schedule::command('buffalo:incident-verification-reminders')->everyFiveMinutes()->withoutOverlapping();

// Reports (Phase 7D): scheduled runs, and deleting expired files.
Schedule::command('buffalo:run-report-schedules')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('buffalo:expire-reports')->hourly();

// Audit integrity (Phase 8A): verify the hash chains, then anchor their heads.
Schedule::command('buffalo:audit:verify')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('buffalo:audit:anchor')->dailyAt('02:45');

// Malware scanning (Phase 8B.4).
Schedule::command('buffalo:malware:scan-quarantined')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('buffalo:malware:check-signatures')->dailyAt('06:45');
