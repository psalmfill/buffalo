<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scopes the request to the authenticated user's jurisdiction, or to
 * nothing when there is no user. Runs in the `web` group after the session
 * has started and before route model binding (see bootstrap/app.php), so
 * bound models are already jurisdiction-scoped.
 */
class SetJurisdictionContext
{
    public function __construct(private readonly JurisdictionContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->context->user($user);
        } else {
            $this->context->none();
        }

        return $next($request);
    }
}
