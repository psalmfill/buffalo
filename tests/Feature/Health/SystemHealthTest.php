<?php

use App\Jobs\SchedulerWatchdogJob;
use App\Notifications\SystemAlert;
use App\Support\Config\Secrets;
use App\Support\Health\Checks;
use App\Support\Health\HealthStatus;
use App\Support\Health\SchedulerCheck;
use App\Support\Health\StackChecks;
use App\Support\Health\SystemAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8C.4: System health page, /health/detailed, and the stack alerts.
*/

beforeEach(function () {
    Notification::fake();
    $this->world = JurisdictionWorld::build();
    $this->admin = $this->world->users['super_admin'];
    HealthStatus::record('audit.verify', true, 'Every audit chain is intact.');
    HealthStatus::record('schedule.buffalo:health:monitor', true);
});

afterEach(fn () => Checks::$measure = null);

function stackConditions(): array
{
    return collect(app(StackChecks::class)->conditions())->mapWithKeys(fn ($c) => [$c->key => $c->severity])->all();
}

test('super admins see System health; nobody else does', function () {
    CrossFixtures::signIn($this->admin)->get('/system/health')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('system/health')
            ->has('report.services', 8)
            ->has('report.backups.database')
            ->has('report.storage.disks')
            ->has('report.keys')
            ->where('report.audit.last_verify.ok', true));

    foreach (['director_general', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief'] as $role) {
        CrossFixtures::signIn($this->world->users[$role])->get('/system/health')->assertForbidden();
    }
});

test('/health/detailed needs the token, sets no cookies, and is 404 without a configured token', function () {
    putenv('BUFFALO_HEALTH_TOKEN=');
    $this->getJson('/health/detailed')->assertNotFound();

    putenv('BUFFALO_HEALTH_TOKEN='.str_repeat('t', 40));
    Secrets::flush();

    $this->getJson('/health/detailed')->assertUnauthorized();
    $this->getJson('/health/detailed', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    $response = $this->getJson('/health/detailed', ['Authorization' => 'Bearer '.str_repeat('t', 40)])->assertOk()
        ->assertJsonStructure(['status', 'services', 'queues', 'scheduler', 'backups', 'storage', 'audit', 'keys', 'alerts']);
    expect($response->headers->getCookies())->toBe([]);

    putenv('BUFFALO_HEALTH_TOKEN');
    Secrets::flush();
});

test('disk use over 80% is a warning and over 90% critical', function (float $freeFraction, ?string $severity) {
    Checks::$measure = fn (string $path) => [$freeFraction * 1000, 1000.0];

    $conditions = stackConditions();
    if ($severity === null) {
        expect(array_filter(array_keys($conditions), fn ($k) => str_starts_with($k, 'stack.disk.')))->toBe([]);
    } else {
        expect($conditions['stack.disk.evidence'] ?? null)->toBe($severity);
    }
})->with([
    'half full' => [0.5, null],
    '85% full' => [0.15, 'warning'],
    '95% full' => [0.05, 'critical'],
]);

test('a failing service, many failed jobs and an old signature set raise their alerts', function () {
    $this->services->down['Gotenberg'] = 'Connection refused';
    foreach (range(1, 11) as $i) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    }
    $this->malware->signaturesDate = now()->toImmutable()->subDays(10);

    expect(stackConditions())->toMatchArray([
        'stack.service.gotenberg' => 'critical',
        'stack.queue.failed' => 'warning',
        'stack.clamav.signatures' => 'warning',
    ]);
});

test('an audit verification not run for 26 hours is a warning', function () {
    $this->travel(27)->hours();
    HealthStatus::record('schedule.buffalo:health:monitor', true);

    expect(stackConditions())->toHaveKey('stack.audit.not_run');
});

test('a TLS certificate close to expiry is critical, a month away a warning', function (int $days, ?string $severity) {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'buffalo.test'], $key), null, $key, $days);
    $path = tempnam(sys_get_temp_dir(), 'crt');
    openssl_x509_export_to_file($cert, $path);
    config(['buffalo.health.tls_certificate' => $path]);

    expect(stackConditions()['stack.tls.expiry'] ?? null)->toBe($severity);
    unlink($path);
})->with([
    '5 days' => [5, 'critical'],
    '20 days' => [20, 'warning'],
    '200 days' => [200, null],
]);

test('a stopped scheduler is reported by the queue watchdog, once', function () {
    $this->travel(15)->minutes();

    (new SchedulerWatchdogJob)->handle(app(SchedulerCheck::class), app(SystemAlerts::class));
    (new SchedulerWatchdogJob)->handle(app(SchedulerCheck::class), app(SystemAlerts::class));

    Notification::assertSentTo($this->admin, SystemAlert::class, fn (SystemAlert $n) => $n->key === 'scheduler.stale' && $n->level === 'critical');
    expect(collect(Notification::sent($this->admin, SystemAlert::class))->where('key', 'scheduler.stale'))->toHaveCount(1);

    HealthStatus::record('schedule.buffalo:health:monitor', true);
    (new SchedulerWatchdogJob)->handle(app(SchedulerCheck::class), app(SystemAlerts::class));
    Notification::assertSentTo($this->admin, SystemAlert::class, fn (SystemAlert $n) => $n->key === 'scheduler.stale' && $n->cleared);
});

test('critical system alerts also go by mail with a generic subject; warnings do not', function () {
    $critical = new SystemAlert('stack.disk.evidence', 'critical', 'Disk almost full: Evidence', 'Evidence is 95% full.');
    $warning = new SystemAlert('stack.disk.evidence', 'warning', 'Disk filling up: Evidence', 'Evidence is 85% full.');

    expect($critical->allowsMail())->toBeTrue()->and($warning->allowsMail())->toBeFalse()
        ->and($critical->toMail($this->admin)->subject)->toBe('BUFFALO notification')
        ->and($critical->mailBody($this->admin))->not->toContain('Evidence');
});
