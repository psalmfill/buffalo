<?php

use App\Actions\Reports\RequestReport;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\Permission;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Exceptions\ExportQuotaExceeded;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\RecordAccessLog;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\ExportVolumeAlert;
use App\Support\Exports\ExportControls;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8B.3: export quotas, unusual-volume alerts, exports review.
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('reports');
    config(['buffalo.reports.queue_connection' => 'sync']);
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
});

function requestSitrep(User $user, ?ReportSchedule $schedule = null): ReportRun
{
    return app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)($user, ReportType::Sitrep, ReportFormat::Pdf, [], $schedule));
}

function completedRun(User $user, Classification $level): ReportRun
{
    Storage::disk('reports')->put('runs/q.pdf', '%PDF-q');
    $run = new ReportRun;
    $run->forceFill([
        'report_type' => ReportType::Sitrep, 'format' => ReportFormat::Pdf, 'parameters' => [], 'requested_by' => $user->id,
        'status' => ReportRunStatus::Completed, 'classification' => $level, 'file_path' => 'runs/q.pdf',
        'sha256' => hash('sha256', '%PDF-q'), 'size_bytes' => 6, 'completed_at' => now(), 'expires_at' => now()->addDays(30),
    ])->save();

    return $run;
}

test('the daily report quota blocks further reports; scheduled runs do not count', function () {
    config(['buffalo.exports.daily_report_runs' => 2]);
    requestSitrep($this->sd);
    requestSitrep($this->sd);

    expect(fn () => requestSitrep($this->sd))->toThrow(ExportQuotaExceeded::class, "today's limit of 2 reports");

    // A schedule's run still goes ahead.
    $schedule = new ReportSchedule;
    $schedule->forceFill(['user_id' => $this->sd->id, 'report_type' => ReportType::Sitrep, 'parameters' => [], 'format' => ReportFormat::Pdf, 'cadence' => 'daily', 'time_of_day' => '07:00', 'is_active' => true, 'next_run_at' => now()])->save();
    expect(requestSitrep($this->sd, $schedule)->exists)->toBeTrue();

    // On the page, the message comes back as an error; viewing is unaffected.
    CrossFixtures::signIn($this->sd)->from('/reports')->post('/reports', ['type' => 'sitrep', 'format' => 'pdf'])
        ->assertRedirect('/reports')->assertSessionHasErrors(['quota']);
    CrossFixtures::signIn($this->sd)->get('/incidents')->assertOk();

    // Tomorrow, the quota is fresh.
    $this->travel(1)->days();
    expect(requestSitrep($this->sd)->exists)->toBeTrue();
});

test('the daily row quota counts incident list exports', function () {
    config(['buffalo.exports.daily_rows' => 3]);
    foreach (range(1, 2) as $i) {
        Incident::factory()->in($this->world->lgas['A1a'])->create(['status' => 'verified', 'reference_no' => "BUF/T/T/2026/00010{$i}", 'submitted_at' => now()]);
    }
    $export = fn () => app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(RequestReport::class)($this->sd, ReportType::IncidentList, ReportFormat::Csv, ['filters' => []], stepUpConfirmed: true));

    expect($export()->row_count)->toBe(2)
        ->and(fn () => $export())->toThrow(ExportQuotaExceeded::class, 'past today\'s limit of 3 exported rows (2 used)');
});

test('the daily evidence download quota is enforced from the access log', function () {
    config(['buffalo.exports.daily_evidence_downloads' => 2]);
    $controls = app(ExportControls::class);
    $evidence = IncidentEvidence::class;

    foreach (range(1, 2) as $i) {
        RecordAccessLog::query()->create(['user_id' => $this->sd->id, 'subject_type' => (new $evidence)->getMorphClass(), 'subject_id' => $i, 'action' => AccessAction::Download, 'context' => null]);
    }

    expect(fn () => $controls->assertCanDownloadEvidence($this->sd))->toThrow(ExportQuotaExceeded::class, 'limit of 2 evidence downloads')
        ->and(fn () => $controls->assertCanDownloadEvidence($this->world->users['deputy_state_director']))->not->toThrow(ExportQuotaExceeded::class);
});

test('passing half a quota alerts the DG and the covering DDGs once, never the exporter', function () {
    config(['buffalo.exports.daily_report_runs' => 4]);
    $dg = $this->world->users['director_general'];
    $zoneA = $this->world->users['ddg_zone_a'];
    $zoneB = $this->world->users['ddg_zone_b'];

    requestSitrep($this->sd);
    requestSitrep($this->sd);
    Notification::assertNothingSent();

    requestSitrep($this->sd);   // 3 of 4: past half
    requestSitrep($this->sd);   // still today: no second alert

    Notification::assertSentTo([$dg, $zoneA], ExportVolumeAlert::class, function (ExportVolumeAlert $n) use ($dg) {
        $body = $n->body($dg);

        return $n->rule === 'half_report_runs' && str_contains($body, $this->sd->name) && str_contains($body, 'requested 3 reports (limit 4)');
    });
    Notification::assertNotSentTo([$zoneB, $this->sd], ExportVolumeAlert::class);
    expect(Notification::sent($dg, ExportVolumeAlert::class))->toHaveCount(1)
        ->and(DB::table('export_alerts')->where('user_id', $this->sd->id)->count())->toBe(1);
});

test('more than the allowed secret exports in a day alerts once', function () {
    config(['buffalo.exports.secret_alert_count' => 1]);
    $run = completedRun($this->sd, Classification::Secret);

    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();
    Notification::assertNothingSent();
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();

    Notification::assertSentTo($this->world->users['director_general'], ExportVolumeAlert::class, fn (ExportVolumeAlert $n) => $n->rule === 'secret_exports' && $n->usage['secret_exports'] === 2);
    expect(Notification::sent($this->world->users['director_general'], ExportVolumeAlert::class))->toHaveCount(1);
});

test('the exports review lists exports with classification and rows; DDGs see their zones only', function () {
    $own = completedRun($this->sd, Classification::Secret);
    $b1 = CrossFixtures::directorOfB1($this->world);
    $other = completedRun($b1, Classification::Restricted);
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$own->ulid}/download")->assertOk();
    CrossFixtures::signIn($b1)->get("/reports/runs/{$other->ulid}/download")->assertOk();

    CrossFixtures::signIn($this->world->users['director_general'])->get('/audit/exports')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('audit/exports')->has('entries.data', 2)
            ->where('entries.data.1.officer', $this->sd->name)
            ->where('entries.data.1.classification', 'Secret')
            ->where('entries.data.1.action', 'Exported')
            ->where('entries.data.1.what', 'Daily situation report · RPT-'.mb_strtoupper($own->ulid)));

    CrossFixtures::signIn($this->world->users['ddg_zone_a'])->get('/audit/exports')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.officer', $this->sd->name));
    CrossFixtures::signIn($this->world->users['director_general'])->get('/audit/exports?classification=restricted')
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.officer', $b1->name));
});

test('the exports review needs audit.exports: not super admins by default, nor state roles', function () {
    $admin = $this->world->users['super_admin'];
    expect($admin->checkPermissionTo(Permission::AuditView->value))->toBeTrue();

    CrossFixtures::signIn($admin)->get('/audit/exports')->assertForbidden();
    CrossFixtures::signIn($this->sd)->get('/audit/exports')->assertForbidden();

    $admin->givePermissionTo(Permission::AuditExports->value);
    CrossFixtures::signIn($admin->fresh())->get('/audit/exports')->assertOk();
});
