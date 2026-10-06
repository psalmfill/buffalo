<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventAuthenticatedCaching;
use App\Http\Middleware\RequireRecentTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetJurisdictionContext;
use App\Http\Middleware\VerifyKeyFingerprints;
use App\Http\Responses\ErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // Our own health check: Laravel's page loads CDN assets (Phase 8B.7).
        // Outside the web group, so a probe never starts a session.
        then: function (): void {
            Route::get('up', HealthController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Browser security headers on every response (Phase 8B.1).
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['csp-report']);
        $middleware->alias(['step-up' => RequireRecentTwoFactor::class]);

        // Production only: never serve with keys that do not match the
        // database's active fingerprints (Phase 8A).
        $middleware->prepend(VerifyKeyFingerprints::class);

        $middleware->web(append: [
            SetJurisdictionContext::class,
            EnforceSessionPolicy::class,
            EnsureTwoFactorEnrolled::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventAuthenticatedCaching::class,
        ]);

        // The jurisdiction context must be set before route model binding
        // resolves jurisdiction-scoped models (and after authentication).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetJurisdictionContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash these into the session (stored in Valkey) on a
        // validation error: passwords, and person fields that are encrypted
        // at rest (Phase 3A).
        $exceptions->dontFlash([
            'current_password', 'password', 'password_confirmation',
            'nin', 'phone', 'address', 'date_of_birth',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // BUFFALO-styled 403/404/419/500/503 pages (App\Http\Responses\ErrorPage).
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => ErrorPage::render($response, $request));
    })->create();
