<?php

namespace App\Http\Middleware;

use App\Support\Auth\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passes when the user confirmed a TOTP code within the step-up window
 * (Phase 8B.2); otherwise asks for one (StepUpRequired). Alias `step-up`.
 * Actions whose need depends on the record (a secret report, a large
 * export) call StepUp::ensure() themselves.
 */
class RequireRecentTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        StepUp::ensure($request);

        return $next($request);
    }
}
