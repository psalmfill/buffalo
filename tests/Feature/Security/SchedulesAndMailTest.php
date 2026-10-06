<?php

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UserJurisdiction;
use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Enums\SchedulePauseReason;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\AccountSetupNotification;
use App\Notifications\AuditChainBroken;
use App\Notifications\ReportReady;
use App\Notifications\ReportSchedulesPaused;
use App\Notifications\SystemAlert;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Auth\PasswordResetNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8B.5: report schedules pause on a posting change; mail subjects and
| bodies carry no references, places, incident types or classifications.
*/

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
});

function scheduleFor(User $user, bool $active = true): ReportSchedule
{
    $schedule = new ReportSchedule;
    $schedule->forceFill([
        'user_id' => $user->id, 'report_type' => ReportType::Sitrep, 'parameters' => [], 'format' => ReportFormat::Pdf,
        'cadence' => 'daily', 'time_of_day' => '07:00', 'is_active' => $active, 'next_run_at' => now()->addHour(),
    ])->save();

    return $schedule;
}

/*
|--------------------------------------------------------------------------
| Schedules
|--------------------------------------------------------------------------
*/

test('a posting change pauses every schedule, notifies the owner, and resuming is one click', function () {
    Notification::fake();
    [$first, $second] = [scheduleFor($this->sd), scheduleFor($this->sd)];
    $other = scheduleFor($this->world->users['deputy_state_director']);

    app(UpdateUserJurisdiction::class)($this->sd, new UserJurisdiction(Role::StateDirector, $this->world->states['B1']), $this->world->users['super_admin']);

    foreach ([$first, $second] as $schedule) {
        $schedule->refresh();
        expect($schedule->is_active)->toBeFalse()
            ->and($schedule->paused_reason)->toBe(SchedulePauseReason::PostingChange)
            ->and($schedule->paused_at)->not->toBeNull();
    }
    expect($other->fresh()?->is_active)->toBeTrue();
    Notification::assertSentTo($this->sd, ReportSchedulesPaused::class, fn (ReportSchedulesPaused $n) => $n->count === 2);

    $owner = $this->sd->fresh();
    CrossFixtures::signIn($owner)->get('/reports/schedules')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('schedules.0.review', true)
            ->where('schedules.0.paused_reason', SchedulePauseReason::PostingChange->label()));

    CrossFixtures::signIn($owner)->patch("/reports/schedules/{$first->ulid}", ['is_active' => true])->assertRedirect();
    $first->refresh();
    expect($first->is_active)->toBeTrue()->and($first->paused_reason)->toBeNull();
});

test('a save without any change pauses nothing', function () {
    Notification::fake();
    $schedule = scheduleFor($this->sd);

    app(UpdateUserJurisdiction::class)($this->sd, new UserJurisdiction(Role::StateDirector, $this->sd->state));

    expect($schedule->fresh()?->is_active)->toBeTrue();
    Notification::assertNotSentTo($this->sd, ReportSchedulesPaused::class);
});

test('deactivation pauses schedules at once; the runner also pauses blocked owners', function () {
    Notification::fake();
    $schedule = scheduleFor($this->sd);

    app(DeactivateUser::class)($this->sd, 'Left the service', $this->world->users['super_admin']);

    $schedule->refresh();
    expect($schedule->is_active)->toBeFalse()->and($schedule->paused_reason)->toBe(SchedulePauseReason::Deactivated);
    Notification::assertNotSentTo($this->sd, ReportSchedulesPaused::class);

    // An owner blocked another way (not deactivated through the action).
    $chief = $this->world->users['lga_chief'];
    $chief->forceFill(['is_active' => false])->save();
    $due = scheduleFor($chief);
    $due->forceFill(['next_run_at' => now()->subMinute()])->save();
    $this->artisan('buffalo:run-report-schedules')->assertSuccessful();

    expect($due->fresh()?->paused_reason)->toBe(SchedulePauseReason::OwnerBlocked);
});

/*
|--------------------------------------------------------------------------
| Mail
|--------------------------------------------------------------------------
*/

/** Every notification that can be sent by mail, by class, with its expected subject. */
function mailables(User $user): array
{
    $run = new ReportRun;
    $run->forceFill([
        'report_type' => ReportType::IncidentList, 'format' => ReportFormat::Pdf, 'parameters' => [], 'requested_by' => $user->id,
        'status' => ReportRunStatus::Completed, 'classification' => Classification::Secret, 'file_path' => 'runs/x.pdf',
        'sha256' => str_repeat('a', 64), 'size_bytes' => 1, 'completed_at' => now(), 'expires_at' => now()->addDay(),
    ])->save();

    return [
        ReportReady::class => [new ReportReady($run), 'BUFFALO: report ready', [$run->ulid, 'incident list', 'Secret']],
        AccountSetupNotification::class => [new AccountSetupNotification('https://buffalo.test/setup/token'), 'BUFFALO: account setup', []],
        AuditChainBroken::class => [new AuditChainBroken('incident_status_history', 4242), 'BUFFALO notification', ['incident_status_history', '4242']],
        TwoFactorResetNotification::class => [new TwoFactorResetNotification, 'BUFFALO notification', []],
        PasswordResetNotification::class => [new PasswordResetNotification('reset-token'), 'BUFFALO: password reset', []],
        SystemAlert::class => [new SystemAlert('stack.disk.evidence', 'critical', 'Disk almost full: Evidence', 'Evidence (/var/lib/buffalo/evidence) is 95% full.'), 'BUFFALO notification', ['/var/lib/buffalo', '95%']],
    ];
}

test('every mail-capable notification is covered here', function () {
    $mailCapable = collect(File::files(app_path('Notifications')))
        ->filter(fn (SplFileInfo $file) => preg_match('/function allowsMail\(|function toMail/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file) => 'App\\Notifications\\'.$file->getBasename('.php'))
        ->reject(fn (string $class) => (new ReflectionClass($class))->isAbstract())
        // Laravel's password reset mail, with our subject (not a BuffaloNotification).
        ->push(PasswordResetNotification::class)
        ->sort()->values()->all();

    $covered = array_keys(mailables($this->sd));
    sort($covered);

    expect($mailCapable)->toBe($covered);
});

test('mail subjects are generic and mails carry no references, places, report types or classifications', function () {
    $user = $this->sd;
    $place = $user->state->name;

    foreach (mailables($user) as $class => [$notification, $subject, $forbidden]) {
        $mail = $notification->toMail($user);
        $rendered = (string) $mail->render();
        $text = strip_tags($rendered);

        expect($mail->subject)->toBe($subject, $class);

        foreach ([...$forbidden, $place, 'Confidential', 'Restricted'] as $detail) {
            expect(mb_stripos($mail->subject, (string) $detail))->toBeFalse("{$class} subject contains {$detail}")
                ->and(mb_stripos($text, (string) $detail))->toBeFalse("{$class} mail contains {$detail}");
        }
    }
});

test('other notifications fall back to the generic subject', function () {
    expect((new ReportSchedulesPaused(2))->toMail($this->sd)->subject)->toBe('BUFFALO notification');
});
