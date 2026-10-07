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
        'buffalo.rollout.enforce' => true,
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
    'rollout gating off' => [['buffalo.rollout.enforce' => false], 'Pilot rollout gating must be enforced'],
]);
