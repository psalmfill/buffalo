<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Health check at /up (Phase 8B.7), replacing Laravel's built-in page, which
 * loads fonts and Tailwind from public CDNs (forbidden: no runtime requests
 * to the internet). Same behaviour otherwise: DiagnosingHealth listeners run,
 * 200 when they pass, 500 when one throws. No session, no data.
 */
final class HealthController
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        try {
            Event::dispatch(new DiagnosingHealth);
            $up = true;
        } catch (Throwable) {
            $up = false;
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => $up ? 'up' : 'down'], $up ? 200 : 500);
        }

        return response($up ? 'up' : 'down', $up ? 200 : 500, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
