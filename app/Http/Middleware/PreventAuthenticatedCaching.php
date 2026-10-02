<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pages seen while signed in are never stored by the browser (Phase 8A.6):
 * `Cache-Control: no-store` keeps them out of the HTTP cache and the
 * back/forward cache, so after signing out, Back cannot show them again.
 * File downloads keep their own headers.
 */
class PreventAuthenticatedCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null && ! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
