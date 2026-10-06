<?php

namespace App\Http\Controllers;

use App\Support\Config\Secrets;
use App\Support\Health\SystemHealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /health/detailed (Phase 8C.4): the System health report as JSON for
 * external monitoring. Requires `Authorization: Bearer <BUFFALO_HEALTH_TOKEN>`
 * (a secret); answers 404 when no token is configured. nginx serves it only
 * to internal networks. Outside the web group: no session, no cookies.
 */
final class HealthDetailedController
{
    public function __invoke(Request $request, SystemHealthReport $report): JsonResponse
    {
        $token = Secrets::get('BUFFALO_HEALTH_TOKEN');
        abort_if($token === null || $token === '', 404);

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($token, $given)) {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $body = $report->build();
        /** @var list<array{severity: string}> $alerts */
        $alerts = $body['alerts'];
        /** @var list<array{ok: bool}> $services */
        $services = $body['services'];
        $critical = array_filter($alerts, fn (array $a) => $a['severity'] === 'critical') !== [];
        $allUp = array_filter($services, fn (array $s) => ! $s['ok']) === [];
        $status = $critical ? 'critical' : ($allUp && $alerts === [] ? 'ok' : 'warning');

        return response()->json(['status' => $status, ...$body])->header('Cache-Control', 'no-store');
    }
}
