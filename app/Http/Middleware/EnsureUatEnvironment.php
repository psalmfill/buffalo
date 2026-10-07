<?php

namespace App\Http\Middleware;

use App\Support\Uat\Uat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UAT-only routes (Phase 9B.5) answer 404 outside the UAT environment.
 * They are also not registered at all in a production deployment.
 */
class EnsureUatEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Uat::enabled(), 404);

        return $next($request);
    }
}
