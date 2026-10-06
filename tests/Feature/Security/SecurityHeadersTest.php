<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\CrossJurisdiction\CrossFixtures;

/*
| Phase 8B.1: browser security headers.
*/

/** The nonce in a response's CSP header. */
function cspNonce(string $policy): ?string
{
    return preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $m) === 1 ? $m[1] : null;
}

/**
 * Executable inline or external scripts in a page and whether each carries $nonce.
 *
 * @return list<array{tag: string, ok: bool}>
 */
function scriptTags(string $html, string $nonce): array
{
    preg_match_all('/<script\b[^>]*>/i', $html, $matches);

    return array_values(array_map(fn (string $tag) => ['tag' => $tag, 'ok' => str_contains($tag, 'nonce="'.$nonce.'"')], array_filter(
        $matches[0],
        // JSON data blocks are not executed and are not governed by script-src.
        fn (string $tag) => ! str_contains($tag, 'type="application/json"'),
    )));
}

test('HTML, JSON and file responses carry the security headers', function () {
    $user = User::factory()->directorGeneral()->create();
    Route::middleware('web')->get('/_test/file', fn () => response()->streamDownload(fn () => print 'x', 'x.txt'));

    $responses = [
        'html' => $this->get('/login'),
        'json' => CrossFixtures::signIn($user)->getJson('/notifications'),
        'file' => $this->get('/_test/file'),
    ];

    foreach ($responses as $kind => $response) {
        $policy = (string) $response->headers->get('Content-Security-Policy');
        expect($policy)->toContain("default-src 'self'")
            ->toContain("script-src 'self' 'nonce-")
            ->toContain("frame-ancestors 'none'")
            ->toContain("object-src 'none'")
            ->toContain("base-uri 'self'")
            ->toContain("form-action 'self'")
            ->toContain('connect-src \'self\' '.config('buffalo.security.csp.websocket'))
            ->not->toContain("'unsafe-eval'")
            ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('microphone=()')->toContain('geolocation=()')->toContain('payment=()')->toContain('usb=()')
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
            // nginx sends these; Laravel does not duplicate them.
            ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
        expect(preg_match("/script-src[^;]*'unsafe-inline'/", $policy))->toBe(0);
    }
});

test('the nonce differs per request and every script in the page carries it', function () {
    $first = $this->get('/login');
    $second = $this->get('/login');
    $nonce = cspNonce((string) $first->headers->get('Content-Security-Policy'));

    expect($nonce)->not->toBeNull()
        ->and(strlen((string) $nonce))->toBeGreaterThanOrEqual(32)
        ->and(cspNonce((string) $second->headers->get('Content-Security-Policy')))->not->toBe($nonce);

    $scripts = scriptTags((string) $first->getContent(), (string) $nonce);
    expect($scripts)->not->toBeEmpty()
        ->and(collect($scripts)->reject(fn (array $s) => $s['ok'])->pluck('tag')->all())->toBe([])
        ->and((string) $first->getContent())->toContain('<meta name="csp-nonce" content="'.$nonce.'">');
});

test('the signed-in app shell and Horizon render no script without the nonce', function () {
    $admin = User::factory()->superAdmin()->create();

    foreach (['/dashboard', '/horizon'] as $path) {
        $response = CrossFixtures::signIn($admin)->get($path);
        $response->assertOk();
        $nonce = (string) cspNonce((string) $response->headers->get('Content-Security-Policy'));

        expect(collect(scriptTags((string) $response->getContent(), $nonce))->reject(fn (array $s) => $s['ok'])->pluck('tag')->all())->toBe([], $path);
    }
});

test('report-only mode and HSTS follow configuration', function () {
    config(['buffalo.security.csp.report_only' => true]);
    $response = $this->get('/login');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();

    config(['buffalo.security.csp.report_only' => false, 'app.url' => 'https://buffalo.example.gov.ng']);
    expect($this->get('/login')->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});

test('violation reports are logged without query strings or page content', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'CSP violation'
        && $context['directive'] === 'script-src-elem'
        && $context['blocked'] === 'https://evil.example'
        && $context['page'] === '/persons/01abc');

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
        'document-uri' => 'http://localhost:8000/persons/01abc?q=Adewale',
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://evil.example/x.js?token=secret',
    ]]))->assertNoContent();
});

test('a response that sets its own stricter policy keeps it', function () {
    Route::middleware('web')->get('/_test/sandboxed', fn () => response('x')->header('Content-Security-Policy', "default-src 'none'; sandbox"));

    $response = $this->get('/_test/sandboxed');
    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
});
