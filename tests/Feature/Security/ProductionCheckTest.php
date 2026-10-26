<?php

use Illuminate\Support\Facades\Artisan;

/*
| Phase 9A.1: production boot refuses unsafe settings.
*/

function productionSafe(): void
{
    config([
        'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://buffalo.example.gov.ng',
        'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
        'buffalo.security.csp.report_only' => false, 'inertia.devtools.enabled' => false,
        'buffalo.rollout.enforce' => true, 'session.lifetime' => 30,
        'buffalo.edge.mode' => 'direct', 'buffalo.edge.published' => 'direct', 'buffalo.edge.bind' => '0.0.0.0',
        'buffalo.edge.ports' => '80,443', 'buffalo.edge.basic_auth' => false,
    ]);
}

test('safe production settings pass', function () {
    productionSafe();

    expect(Artisan::call('buffalo:production:check'))->toBe(0);
});

test('each unsafe setting is refused', function (array $config, string $message) {
    productionSafe();
    config($config);

    expect(Artisan::call('buffalo:production:check'))->toBe(1)
        ->and(Artisan::output())->toContain($message);
})->with([
    'debug on' => [['app.debug' => true], 'APP_DEBUG must be false'],
    'http url' => [['app.url' => 'http://buffalo.example.gov.ng'], 'APP_URL must start with https://'],
    'report-only CSP' => [['buffalo.security.csp.report_only' => true], 'Content Security Policy must be enforced'],
    'insecure cookies' => [['session.secure' => false], 'Session cookies must be Secure'],
    'not production' => [['app.env' => 'local'], 'APP_ENV must be production'],
    'devtools on' => [['inertia.devtools.enabled' => true], 'Inertia DevTools must be off'],
    'long idle timeout' => [['session.lifetime' => 120], 'The idle timeout must be at most 30 minutes'],
    'rollout gating off' => [['buffalo.rollout.enforce' => false], 'Pilot rollout gating must be enforced'],
    // Edge mode.
    'unknown edge mode' => [['buffalo.edge.mode' => 'tunnel'], 'BUFFALO_EDGE must be direct or proxy'],
    'local edge mode outside UAT' => [['buffalo.edge.mode' => 'local'], 'BUFFALO_EDGE must be direct or proxy'],
    'proxy without the overlay' => [['buffalo.edge.mode' => 'proxy'], 'compose.edge-proxy.yml is not in COMPOSE_FILE'],
    'overlay without proxy' => [['buffalo.edge.published' => 'proxy', 'buffalo.edge.bind' => '127.0.0.1'], 'COMPOSE_FILE includes compose.edge-proxy.yml'],
    'proxy on a public address' => [['buffalo.edge.mode' => 'proxy', 'buffalo.edge.published' => 'proxy', 'buffalo.edge.bind' => '0.0.0.0'], 'BUFFALO_EDGE_BIND must be 127.0.0.1'],
    'proxy on all IPv6 addresses' => [['buffalo.edge.mode' => 'proxy', 'buffalo.edge.published' => 'proxy', 'buffalo.edge.bind' => '::'], 'BUFFALO_EDGE_BIND must be 127.0.0.1'],
    'no edge information from compose' => [['buffalo.edge.published' => null], 'BUFFALO_EDGE_PUBLISHED'],
    'site password in production' => [['buffalo.edge.basic_auth' => true], 'BUFFALO_BASIC_AUTH'],
]);

test('edge mode proxy on 127.0.0.1 passes, with APP_URL still required to be https', function () {
    productionSafe();
    config(['buffalo.edge.mode' => 'proxy', 'buffalo.edge.published' => 'proxy', 'buffalo.edge.bind' => '127.0.0.1', 'buffalo.edge.ports' => '8090']);
    expect(Artisan::call('buffalo:production:check'))->toBe(0);

    config(['app.url' => 'http://buffalo.example.gov.ng']);
    expect(Artisan::call('buffalo:production:check'))->toBe(1)
        ->and(Artisan::output())->toContain('APP_URL must start with https://');
});

test('UAT also accepts the local edge mode and the site password', function () {
    productionSafe();
    config(['app.env' => 'uat', 'buffalo.edge.mode' => 'local', 'buffalo.edge.bind' => '127.0.0.1', 'buffalo.edge.basic_auth' => true]);

    expect(Artisan::call('buffalo:production:check', ['--uat' => true]))->toBe(0);
});
