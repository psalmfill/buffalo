<?php

namespace App\Http\Middleware;

use App\Support\Lens\StateLens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a list, the incident map or analytics on the state lens (Phase
 * 11A.2): a plain visit (no place filter in the URL) is redirected to the
 * same page with the list's own `state` or `zone` filter set to the lens,
 * so the filter bar shows it and the URL can be shared.
 *
 * Any explicit place filter in the URL (`state`, `zone`, `lga`, even
 * empty) or `lens=off` (sent when the user clears the filter) always wins.
 * The lens is only a pre-set filter; scopes are unchanged.
 */
class ApplyStateLens
{
    private const PLACE_KEYS = ['state', 'zone', 'lga', 'lens'];

    public function __construct(private readonly StateLens $lens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->expectsJson() && ! $request->header('X-Inertia')) {
            return $next($request);
        }

        foreach (self::PLACE_KEYS as $key) {
            if ($request->query->has($key)) {
                return $next($request);
            }
        }

        $filter = $this->lens->filter($request);

        if ($filter === []) {
            return $next($request);
        }

        return redirect()->to($request->url().'?'.http_build_query([...$request->query(), ...$filter]));
    }
}
