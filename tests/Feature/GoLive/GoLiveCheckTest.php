<?php

use App\Actions\GoLive\RecordGoLiveDecision;
use App\Enums\GoLiveDecision;
use App\Models\GoLiveDecisionRecord;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditChain;
use App\Support\Audit\AuditLog;
use App\Support\Auth\StepUp;
use App\Support\GoLive\BootChecks;
use App\Support\GoLive\GoLiveCheck;
use App\Support\GoLive\TlsProbe;
use App\Support\Health\HealthStatus;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Malware\MalwareScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\GoLive\GoLiveFixtures;

/*
| Go-live check (Phase 9B.6): every item fails correctly in a fixture setup
| and everything passes in a fully prepared one.
*/

final class FakeBootChecks extends BootChecks
{
    /** @var list<string> */
    public array $failing = [];

    public function failing(): array
    {
        return $this->failing;
    }
}

final class FakeTlsProbe extends TlsProbe
{
    public ?CarbonImmutable $expiry = null;

    public function servedCertificateExpiry(): ?CarbonImmutable
    {
        return $this->expiry;
    }
}

/** A backup status directory as the backup services write it. */
function writeBackupStatus(string $dir, array $overrides = []): void
{
    $o = $overrides + [
        'full_at' => now()->subDays(2), 'diff_at' => now()->subHours(3), 'snapshot_at' => now()->subHours(5),
        'repo2' => true, 'drill' => ['at' => now()->subDays(3)->toIso8601String(), 'result' => 'pass', 'seconds' => 40, 'within_rto' => true, 'mode' => 'full', 'host' => 'buffalo-prod-1'],
    ];
    foreach (['pgbackrest', 'restic', 'drill'] as $sub) {
        File::ensureDirectoryExists("{$dir}/{$sub}");
    }
    $backup = fn (string $label, string $type, CarbonImmutable $at) => ['label' => $label, 'type' => $type, 'timestamp' => ['start' => $at->subMinutes(5)->getTimestamp(), 'stop' => $at->getTimestamp()], 'info' => ['size' => 1000, 'repository' => ['size' => 200]]];
    file_put_contents("{$dir}/pgbackrest/info.json", json_encode([['name' => 'buffalo', 'backup' => [
        $backup('F1', 'full', $o['full_at']), $backup('D1', 'diff', $o['diff_at']),
    ]]]));
    file_put_contents("{$dir}/pgbackrest/sizes.json", json_encode(['repo1_kb' => 100, 'repo2_kb' => $o['repo2'] ? 100 : null]));
    file_put_contents("{$dir}/restic/sizes.json", json_encode(['repo1_kb' => 100, 'repo2_kb' => $o['repo2'] ? 100 : null]));
    file_put_contents("{$dir}/restic/snapshots.json", json_encode([['time' => $o['snapshot_at']->toIso8601String()]]));
    if ($o['drill'] === null) {
        @unlink("{$dir}/drill/last.json");
    } else {
        file_put_contents("{$dir}/drill/last.json", json_encode($o['drill']));
    }
}

function recordAllDecisions(User $admin): void
{
    foreach (GoLiveDecision::cases() as $decision) {
        app(RecordGoLiveDecision::class)($decision, array_key_first($decision->outcomes()), 'Agreed at the steering meeting.', 'A. Director, client', CarbonImmutable::today(), $admin);
    }
}

function goLiveItems(): array
{
    return collect(app(GoLiveCheck::class)->run()['items'])->keyBy('key')->all();
}

function failingItems(): array
{
    return array_keys(array_filter(goLiveItems(), fn ($item) => ! in_array($item['status'], ['pass', 'recorded'], true)));
}

beforeEach(function () {
    $this->seed([ZonesAndStatesSeeder::class, ReferenceDataSeeder::class]);
    app(JurisdictionContext::class)->system();

    $this->boot = new FakeBootChecks;
    $this->tls = new FakeTlsProbe;
    $this->tls->expiry = now()->addDays(80)->toImmutable();
    app()->instance(BootChecks::class, $this->boot);
    app()->instance(TlsProbe::class, $this->tls);

    $this->dir = sys_get_temp_dir().'/buffalo-golive-'.uniqid();
    config(['buffalo.backups.status_path' => $this->dir, 'buffalo.golive.version' => '1.0.0']);
    writeBackupStatus($this->dir);

    GoLiveFixtures::loadProductionHierarchy();
    $this->admin = User::factory()->superAdmin()->create(['email' => 'admin@agency.gov.ng']);
    HealthStatus::record('audit.verify', true, 'Every audit chain is intact.');
    HealthStatus::record(GoLiveCheck::SCAN_KEY, true, null, ['version' => '1.0.0', 'result' => 'passed', 'scanned_at' => now()->toIso8601String()]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('a fully prepared system passes, and the command exits 0 with a report', function () {
    recordAllDecisions($this->admin);
    expect(failingItems())->toBe([]);

    Storage::fake('local');
    $this->artisan('buffalo:go-live:check')
        ->expectsOutputToContain('Ready to go live')
        ->assertSuccessful();

    $files = Storage::disk('local')->allFiles('go-live');
    expect($files)->toHaveCount(1);
    $report = Storage::disk('local')->get($files[0]);
    expect($report)->toContain('Result: **READY**', '| Hierarchy loaded (37 states, 109 districts, 774 LGAs) | ✓ pass |', 'decided by A. Director, client')
        ->and(HealthStatus::get(GoLiveCheck::STATUS_KEY)['ok'])->toBeTrue();
});

test('each technical item fails on its own', function (Closure $break, string $key) {
    recordAllDecisions($this->admin);
    $break($this);

    expect(failingItems())->toBe([$key]);
})->with([
    'boot checks' => [fn ($t) => $t->boot->failing = ['buffalo:secrets:check'], 'boot_checks'],
    'tls unreadable' => [fn ($t) => $t->tls->expiry = null, 'tls'],
    'tls expiring' => [fn ($t) => $t->tls->expiry = now()->addDays(20)->toImmutable(), 'tls'],
    'demo incidents' => [function ($t) {
        $lga = Lga::query()->firstOrFail();
        Incident::factory()->create([
            'title' => '[Demo] Synthetic incident 9', 'lga_id' => $lga->id, 'state_id' => $lga->state_id,
            'incident_type_id' => IncidentType::query()->value('id'), 'reporting_officer_id' => $t->admin->id,
        ]);
    }, 'demo_data'],
    'synthetic boundaries' => [fn ($t) => DB::table('boundary_sources')->insert(['level' => 'lga', 'source_name' => 'x', 'attribution' => 'x', 'synthetic' => true, 'feature_count' => 1, 'imported_at' => now()]), 'demo_data'],
    'an LGA missing' => [fn ($t) => DB::table('lgas')->where('id', DB::table('lgas')->min('id'))->update(['is_active' => false]), 'hierarchy'],
    'a boundary missing' => [fn ($t) => DB::table('lgas')->where('id', DB::table('lgas')->min('id'))->update(['boundary' => null]), 'boundaries'],
    'no super admin with 2FA' => [fn ($t) => $t->admin->forceFill(['two_factor_confirmed_at' => null])->save(), 'super_admin'],
    'a development account' => [fn ($t) => User::factory()->directorGeneral()->create(['email' => 'dg@buffalo.local']), 'dev_accounts'],
    'old full backup' => [fn ($t) => writeBackupStatus($t->dir, ['full_at' => now()->subDays(10)]), 'backup_database'],
    'no recent backup' => [fn ($t) => writeBackupStatus($t->dir, ['full_at' => now()->subDays(3), 'diff_at' => now()->subDays(2)]), 'backup_database'],
    'old snapshot' => [fn ($t) => writeBackupStatus($t->dir, ['snapshot_at' => now()->subDays(3)]), 'backup_evidence'],
    'no repository 2' => [fn ($t) => writeBackupStatus($t->dir, ['repo2' => false]), 'backup_repo2'],
    'no drill' => [fn ($t) => writeBackupStatus($t->dir, ['drill' => null]), 'restore_drill'],
    'old drill' => [fn ($t) => writeBackupStatus($t->dir, ['drill' => ['at' => now()->subDays(40)->toIso8601String(), 'result' => 'pass', 'within_rto' => true, 'mode' => 'full']]), 'restore_drill'],
    'failed drill' => [fn ($t) => writeBackupStatus($t->dir, ['drill' => ['at' => now()->subDay()->toIso8601String(), 'result' => 'fail', 'within_rto' => true, 'mode' => 'full']]), 'restore_drill'],
    'wrong-keys drill only' => [fn ($t) => writeBackupStatus($t->dir, ['drill' => ['at' => now()->subDay()->toIso8601String(), 'result' => 'pass', 'within_rto' => true, 'mode' => 'wrong-keys']]), 'restore_drill'],
    'old signatures' => [fn ($t) => app(MalwareScanner::class)->signaturesDate = now()->subDays(9)->toImmutable(), 'clamav'],
    'scanner down' => [fn ($t) => app(MalwareScanner::class)->down(), 'clamav'],
    'old audit verification' => [fn ($t) => DB::table('health_status')->where('key', 'audit.verify')->update(['recorded_at' => now()->subDays(2)]), 'audit_chains'],
    'broken audit chain' => [fn ($t) => HealthStatus::record('audit.verify', false, '1 chain(s) broken'), 'audit_chains'],
    'expired exception' => [function ($t) {
        $file = "{$t->dir}/exceptions.yaml";
        file_put_contents($file, "vulnerabilities:\n  - id: CVE-1\n    statement: x\n    expired_at: 2020-01-01\n");
        config(['buffalo.golive.exceptions_file' => $file]);
    }, 'trivy_exceptions'],
    'scan of another version' => [fn ($t) => HealthStatus::record(GoLiveCheck::SCAN_KEY, true, null, ['version' => '0.9.0', 'result' => 'passed']), 'security_scan'],
    'skipped scan' => [fn ($t) => HealthStatus::record(GoLiveCheck::SCAN_KEY, false, null, ['version' => '1.0.0', 'result' => 'skipped']), 'security_scan'],
]);

test('every client decision must be recorded', function () {
    $decisions = array_map(fn (GoLiveDecision $d) => $d->value, GoLiveDecision::cases());
    expect(failingItems())->toBe($decisions)
        ->and(goLiveItems()['mail']['status'])->toBe('missing');

    foreach (GoLiveDecision::cases() as $decision) {
        if ($decision !== GoLiveDecision::Escrow) {
            app(RecordGoLiveDecision::class)($decision, array_key_first($decision->outcomes()), 'Agreed at the steering meeting.', 'A. Director, client', CarbonImmutable::today(), $this->admin);
        }
    }

    expect(failingItems())->toBe(['escrow']);
    $this->artisan('buffalo:go-live:check', ['--no-report' => true])->assertFailed();
});

test('the reference data sign-off lapses when the reference data changes', function () {
    recordAllDecisions($this->admin);
    DB::table('threat_types')->where('is_active', true)->orderBy('code')->limit(1)->update(['name' => 'Changed after sign-off']);

    expect(failingItems())->toBe(['reference_data'])
        ->and(goLiveItems()['reference_data']['detail'])->toContain('has changed since');

    app(RecordGoLiveDecision::class)(GoLiveDecision::ReferenceData, 'signed_off', 'Re-reviewed the changed threat type.', 'A. Director, client', CarbonImmutable::today(), $this->admin);
    expect(failingItems())->toBe([]);
});

test('the real start-up checks fail outside production', function () {
    expect((new BootChecks)->failing())->toContain('buffalo:production:check');
});

test('the scheduled run only records, and exits 0', function () {
    $this->boot->failing = ['buffalo:production:check'];

    expect(Artisan::call('buffalo:go-live:check', ['--record' => true]))->toBe(0)
        ->and(HealthStatus::get(GoLiveCheck::STATUS_KEY)['ok'])->toBeFalse()
        ->and(Artisan::call('buffalo:go-live:check', ['--no-report' => true]))->toBe(1);
});

test('the release scan is recorded from the signed bundle', function () {
    recordAllDecisions($this->admin);
    $file = "{$this->dir}/scan.json";
    file_put_contents($file, json_encode(['version' => '1.0.0', 'result' => 'passed', 'scanned_at' => '2026-11-01T10:00:00Z', 'commit' => 'abc1234', 'images' => [['image' => 'app', 'gate' => 'passed']]]));

    DB::table('health_status')->where('key', GoLiveCheck::SCAN_KEY)->delete();
    expect(failingItems())->toBe(['security_scan']);

    $this->artisan('buffalo:release:record', ['--file' => $file])->assertSuccessful();
    expect(failingItems())->toBe([])
        ->and(HealthStatus::get(GoLiveCheck::SCAN_KEY)['data']['commit'])->toBe('abc1234');

    file_put_contents($file, '{"version": "1.0.0"}');
    $this->artisan('buffalo:release:record', ['--file' => $file])->assertFailed();
});

test('decisions are recorded by super admins with step-up, audited, and never changed', function () {
    $url = route('system.go-live.decisions.store');
    $payload = ['decision' => 'pentest', 'outcome' => 'deferred', 'details' => 'Deferred until after the pilot; planned for Q2.', 'client_representative' => 'B. Officer, client CISO', 'decided_on' => today()->toDateString()];

    $this->actingAs($this->admin)->postJson($url, $payload)->assertStatus(428);

    $this->withSession([StepUp::SESSION_KEY => now()->timestamp]);
    $this->actingAs($this->admin)->post($url, [...$payload, 'outcome' => 'maybe'])->assertSessionHasErrors('outcome');
    $this->actingAs($this->admin)->post($url, [...$payload, 'decided_on' => today()->addDay()->toDateString()])->assertSessionHasErrors('decided_on');
    $this->actingAs($this->admin)->post($url, $payload)->assertRedirect();

    $record = GoLiveDecisionRecord::query()->latest('id')->firstOrFail();
    app(JurisdictionContext::class)->system();
    expect($record->outcome)->toBe('deferred')
        ->and($record->recorded_by)->toBe($this->admin->id)
        ->and(GoLiveDecisionRecord::current()['pentest']->is($record))->toBeTrue()
        ->and(Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('event', 'golive_decision_recorded')->exists())->toBeTrue()
        ->and(goLiveItems()['pentest']['detail'])->toContain('Deferred — decided by B. Officer, client CISO');

    expect(fn () => DB::table('golive_decisions')->where('id', $record->id)->update(['outcome' => 'completed']))
        // Refused by the application role's grants (SELECT, INSERT only) and,
        // for the owner, by the append-only trigger: both SQLSTATE 42501.
        ->toThrow(QueryException::class, 'SQLSTATE[42501]');
});

test('only super admins see the go-live page and may record decisions', function () {
    recordAllDecisions($this->admin);
    HealthStatus::record(GoLiveCheck::STATUS_KEY, false, 'Not ready.', app(GoLiveCheck::class)->run());

    $this->actingAs($this->admin)->get(route('system.go-live'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('system/go-live')->has('check.items')->has('decisions', 8)->has('history', 8));
});

test('other roles may not see the go-live page or record decisions', function () {
    $dg = User::factory()->directorGeneral()->create(['email' => 'dg@agency.gov.ng']);
    $this->actingAs($dg)->get(route('system.go-live'))->assertForbidden();
    $this->withSession([StepUp::SESSION_KEY => now()->timestamp])->actingAs($dg)
        ->post(route('system.go-live.decisions.store'), ['decision' => 'mail', 'outcome' => 'enabled', 'details' => 'Mail relay configured.', 'client_representative' => 'X', 'decided_on' => today()->toDateString()])
        ->assertForbidden();
    expect(GoLiveDecisionRecord::query()->count())->toBe(0);
});

test('System health shows the go-live summary', function () {
    recordAllDecisions($this->admin);
    HealthStatus::record(GoLiveCheck::STATUS_KEY, true, 'Ready.', app(GoLiveCheck::class)->run());

    $this->actingAs($this->admin)->get(route('system.health'))
        ->assertInertia(fn (Assert $page) => $page->where('report.golive.passed', true)->where('report.golive.outstanding', 0));
});

test('go-live decisions are part of the audit hash chain', function () {
    recordAllDecisions($this->admin);
    expect(AuditChain::verify('golive_decisions')['ok'])->toBeTrue()
        ->and(DB::table('golive_decisions')->whereNull('row_hash')->count())->toBe(0);
});
