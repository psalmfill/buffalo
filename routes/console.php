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

// Cross-jurisdiction access (Phase 3C).
Schedule::command('buffalo:escalate-access-requests')->everyFiveMinutes();
Schedule::command('buffalo:expire-access-grants')->everyFifteenMinutes();
Schedule::command('buffalo:match-digest')->dailyAt('06:30');
