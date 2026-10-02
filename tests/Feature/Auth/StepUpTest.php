<?php

use App\Actions\Evidence\StoreEvidence;
use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Enums\ThreatUrgency;
use App\Models\Incident;
use App\Models\LoginHistory;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 8B.2: step-up authentication for sensitive actions.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
});

/** The user's current TOTP code. */
function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
}

/** A fresh session (no step-up) with a TOTP secret. */
function freshSession(User $user)
{
    return CrossFixtures::signInWithoutStepUp($user);
}

function stepUpEvents(User $user): array
{
    return LoginHistory::query()->where('user_id', $user->id)->whereIn('event', ['step_up_success', 'step_up_failed'])->orderBy('id')->get()->map(fn (LoginHistory $h) => $h->event->value)->all();
}

/*
|--------------------------------------------------------------------------
| The step-up endpoint
|--------------------------------------------------------------------------
*/

test('a current TOTP code opens the step-up window and is logged', function () {
    $user = User::factory()->directorGeneral()->withTwoFactor()->create();

    freshSession($user)->postJson('/step-up', ['code' => currentCode($user)])
        ->assertOk()->assertJson(['confirmed' => true]);

    expect(session(StepUp::SESSION_KEY))->toBeInt()
        ->and(stepUpEvents($user))->toBe(['step_up_success']);
});

test('a wrong code is refused, logged and counts toward the lockout; recovery codes are never accepted', function () {
    config(['buffalo.auth.lockout.max_attempts' => 3]);
    $user = User::factory()->directorGeneral()->withTwoFactor()->create();
    $recovery = $user->recoveryCodes()[0];

    freshSession($user)->postJson('/step-up', ['code' => $recovery])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Recovery codes cannot be used here']);
    expect($user->fresh()->recoveryCodes())->toContain($recovery);

    freshSession($user)->postJson('/step-up', ['code' => '000000'])->assertUnprocessable();
    freshSession($user)->postJson('/step-up', ['code' => '111111'])->assertUnprocessable();
    expect(stepUpEvents($user))->toBe(['step_up_failed', 'step_up_failed'])
        ->and($user->fresh()->failed_login_count)->toBe(2);

    freshSession($user)->postJson('/step-up', ['code' => '222222'])->assertUnprocessable();
    expect($user->fresh()->locked_until?->isFuture())->toBeTrue();
});

test('the window lasts 15 minutes, then the code is asked for again', function () {
    $user = User::factory()->superAdmin()->withTwoFactor()->create();
    $target = User::factory()->lgaChief($this->world->lgas['A1a'])->create();

    $session = freshSession($user);
    $session->postJson("/users/{$target->ulid}/reset-two-factor")->assertStatus(428)->assertJson(['step_up' => true]);
    $session->postJson('/step-up', ['code' => currentCode($user)])->assertOk();
    $session->postJson("/users/{$target->ulid}/reset-two-factor")->assertRedirect();

    $this->travel(16)->minutes();
    $session->postJson("/users/{$target->ulid}/reset-two-factor")->assertStatus(428);
});

test('a plain link is sent to the step-up page and continues afterwards', function () {
    $user = User::factory()->directorGeneral()->withTwoFactor()->create();
    Storage::fake('reports');
    $run = secretRun($user);

    freshSession($user)->get("/reports/runs/{$run->ulid}/download")
        ->assertRedirect('/step-up?intended='.urlencode("/reports/runs/{$run->ulid}/download"));

    freshSession($user)->withHeaders(['X-Inertia' => 'true'])
        ->post('/step-up', ['code' => currentCode($user), 'intended' => "/reports/runs/{$run->ulid}/download"])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', "/reports/runs/{$run->ulid}/download");

    // Another site is never a destination.
    $this->travel(1)->minutes();
    freshSession($user)->withHeaders(['X-Inertia' => 'true'])
        ->post('/step-up', ['code' => currentCode($user), 'intended' => '//evil.example/x'])
        ->assertHeader('X-Inertia-Location', route('dashboard'));
});

/** A completed secret report run of $user with a file on the fake disk. */
function secretRun(User $user, Classification $level = Classification::Secret): ReportRun
{
    Storage::disk('reports')->put('runs/test.pdf', '%PDF-test');
    $run = new ReportRun;
    $run->forceFill([
        'report_type' => ReportType::Sitrep, 'format' => ReportFormat::Pdf, 'parameters' => [], 'requested_by' => $user->id,
        'status' => ReportRunStatus::Completed, 'classification' => $level, 'file_path' => 'runs/test.pdf',
        'sha256' => hash('sha256', '%PDF-test'), 'size_bytes' => 9, 'page_count' => 1,
        'completed_at' => now(), 'expires_at' => now()->addDays(30),
    ])->save();

    return $run;
}

/*
|--------------------------------------------------------------------------
| Protected actions
|--------------------------------------------------------------------------
*/

test('secret report downloads need step-up; others do not', function () {
    Storage::fake('reports');
    $sd = $this->world->users['state_director'];
    $secret = secretRun($sd);
    $restricted = secretRun($sd, Classification::Restricted);

    freshSession($sd)->getJson("/reports/runs/{$secret->ulid}/download")->assertStatus(428);
    freshSession($sd)->get("/reports/runs/{$restricted->ulid}/download")->assertOk();
    CrossFixtures::signIn($sd)->get("/reports/runs/{$secret->ulid}/download")->assertOk();
});

test('incident list exports above the threshold need step-up instead of a confirmation', function () {
    config(['buffalo.reports.confirm_rows' => 1, 'buffalo.reports.queue_connection' => 'sync']);
    Storage::fake('reports');
    $sd = $this->world->users['state_director'];
    foreach (range(1, 2) as $i) {
        Incident::factory()->in($this->world->lgas['A1a'])->create(['status' => 'verified', 'reference_no' => "BUF/T/T/2026/00000{$i}", 'submitted_at' => now()]);
    }
    $payload = ['type' => 'incident_list', 'format' => 'csv', 'parameters' => ['filters' => []]];

    freshSession($sd)->withHeaders(['X-Inertia' => 'true'])->post('/reports', $payload)->assertStatus(428)->assertJson(['step_up' => true]);
    expect(ReportRun::query()->count())->toBe(0);

    CrossFixtures::signIn($sd)->post('/reports', $payload)->assertRedirect();
    expect(ReportRun::query()->count())->toBe(1);
});

test('evidence originals above restricted need step-up', function () {
    Storage::fake('evidence');
    $sd = $this->world->users['state_director'];
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($sd)->create();
    $store = fn (Classification $level) => app(JurisdictionContext::class)->actingAs($sd, fn () => app(StoreEvidence::class)(
        $incident, EvidenceFixtures::pdf(), app(EvidenceFileInspector::class)->inspect((string) EvidenceFixtures::pdf()->getRealPath(), 'report.pdf'),
        ['title' => $level->value, 'description' => null, 'classification' => $level], $sd,
    ));
    $restricted = $store(Classification::Restricted);
    $confidential = $store(Classification::Confidential);
    CrossFixtures::system();

    freshSession($sd)->get("/evidence/{$restricted->ulid}/download")->assertOk();
    freshSession($sd)->getJson("/evidence/{$confidential->ulid}/download")->assertStatus(428);
    CrossFixtures::signIn($sd)->get("/evidence/{$confidential->ulid}/download")->assertOk();
});

test('approving a cross-jurisdiction access request needs step-up', function () {
    $owner = CrossFixtures::directorOfB1($this->world);
    CrossFixtures::person($this->world->lgas['B1']);
    $request = CrossFixtures::request($this->world->users['lga_chief']);
    $payload = ['granted_days' => 7, 'granted_scope' => 'full'];

    freshSession($owner)->postJson("/access-requests/{$request->ulid}/approve", $payload)->assertStatus(428);
    expect($request->fresh()->status->value)->toBe('pending');

    CrossFixtures::signIn($owner)->post("/access-requests/{$request->ulid}/approve", $payload)->assertSessionHasNoErrors();
    expect($request->fresh()->status->value)->toBe('approved');
});

test('disseminating a confidential or secret record needs step-up; restricted does not', function () {
    $sd = $this->world->users['state_director'];
    $targets = ['targets' => [['level' => 'state', 'ulid' => $this->world->states['A2']->ulid]], 'reason' => 'Armed men expected to cross into your area this week.'];
    $restricted = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate, 'created_by' => $sd->id]);
    $confidential = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate, 'created_by' => $sd->id], Classification::Confidential);

    freshSession($sd)->post("/threats/{$restricted->ulid}/disseminations", $targets)->assertSessionHasNoErrors()->assertRedirect();
    freshSession($sd)->postJson("/threats/{$confidential->ulid}/disseminations", $targets)->assertStatus(428);
    CrossFixtures::signIn($sd)->post("/threats/{$confidential->ulid}/disseminations", $targets)->assertSessionHasNoErrors()->assertRedirect();
});

test('user management actions need step-up', function () {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->lgaChief($this->world->lgas['A1a'])->create();
    $otherAdmin = User::factory()->superAdmin()->create();
    $plainUser = User::factory()->lgaChief($this->world->lgas['A1b'])->create();

    $actions = [
        ['post', '/users', ['name' => 'New Officer', 'email' => 'new.officer@buffalo.test', 'role' => 'lga_security_chief', 'lga' => $this->world->lgas['A1a']->ulid]],
        ['put', "/users/{$target->ulid}/jurisdiction", ['role' => 'lga_security_chief', 'lga' => $this->world->lgas['A1b']->ulid]],
        ['post', "/users/{$target->ulid}/reset-two-factor", []],
        ['post', "/users/{$target->ulid}/setup-link", ['delivery' => 'manual']],
        ['post', "/users/{$otherAdmin->ulid}/deactivate", ['reason' => 'Left the service in October.']],
    ];

    foreach ($actions as [$method, $uri, $data]) {
        freshSession($admin)->json(strtoupper($method), $uri, $data)->assertStatus(428);
        expect(CrossFixtures::signIn($admin)->json(strtoupper($method), $uri, $data)->status())->not->toBe(428, $uri);
    }

    // Deactivating an officer who is not a super admin does not.
    freshSession($admin)->postJson("/users/{$plainUser->ulid}/deactivate", ['reason' => 'Left the service in October.'])->assertRedirect();
});
