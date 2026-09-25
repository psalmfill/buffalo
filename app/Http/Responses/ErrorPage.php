<?php

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * BUFFALO-styled error pages (errors/status) for 403, 404, 419, 500 and
 * 503. The page says only what happened, never why access was denied or
 * whether a record exists; records outside the user's jurisdiction are 404
 * (the jurisdiction scope hides them from route model binding).
 *
 * JSON callers keep their JSON responses, and with APP_DEBUG on, a 500
 * keeps Laravel's debug page.
 */
final class ErrorPage
{
    public const STATUSES = [403, 404, 419, 500, 503];

    public static function render(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)) {
            return $response;
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        if ($status === 500 && config('app.debug')) {
            return $response;
        }

        return Inertia::render('errors/status', [
            'status' => $status,
            // Passed explicitly: HandleInertiaRequests has not run for every
            // error (e.g. an unknown URL).
            'app' => HandleInertiaRequests::appData(),
            'signedIn' => $request->hasSession() && $request->user() !== null,
        ])->toResponse($request)->setStatusCode($status);
    }
}
