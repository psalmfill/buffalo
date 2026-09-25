<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly reminder to LGA chiefs whose LGA profile is due for review (Phase 2B).
Schedule::command('buffalo:profile-review-reminders')->weeklyOn(1, '07:00');
