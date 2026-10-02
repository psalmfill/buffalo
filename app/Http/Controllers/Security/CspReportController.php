<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content Security Policy violation reports (Phase 8B.1) and logs
 * them to storage/logs/csp.log: the directive, the blocked origin and the
 * page path only (no query strings, no page content). Public by design
 * (browsers send reports without credentials); throttled; no CSRF.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = json_decode((string) $request->getContent(), true);
        $reports = match (true) {
            is_array($payload['csp-report'] ?? null) => [$payload['csp-report']],
            array_is_list(is_array($payload) ? $payload : []) => array_map(fn ($r) => is_array($r['body'] ?? null) ? $r['body'] : [], (array) $payload),
            default => [],
        };

        foreach (array_slice($reports, 0, 20) as $report) {
            Log::channel('csp')->warning('CSP violation', [
                'directive' => self::short($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? null),
                'blocked' => self::origin($report['blocked-uri'] ?? $report['blockedURL'] ?? null),
                'page' => self::path($report['document-uri'] ?? $report['documentURL'] ?? null),
                'source' => self::path($report['source-file'] ?? $report['sourceFile'] ?? null),
                'line' => is_numeric($report['line-number'] ?? $report['lineNumber'] ?? null) ? (int) ($report['line-number'] ?? $report['lineNumber']) : null,
                'sample' => self::short($report['script-sample'] ?? $report['sample'] ?? null, 40),
            ]);
        }

        return response()->noContent();
    }

    private static function short(mixed $value, int $length = 100): ?string
    {
        return is_string($value) ? mb_substr($value, 0, $length) : null;
    }

    /** "inline", "eval", "data" or a scheme://host[:port], never a full URL. */
    private static function origin(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parts = parse_url($value);

        return isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : mb_substr($value, 0, 20);
    }

    private static function path(mixed $value): ?string
    {
        return is_string($value) ? mb_substr((string) (parse_url($value, PHP_URL_PATH) ?? ''), 0, 200) : null;
    }
}
