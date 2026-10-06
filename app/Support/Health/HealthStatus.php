<?php

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The latest result per key (Phase 8C.4): scheduled command runs
 * ("schedule.<command>"), the audit verification ("audit.verify").
 */
final class HealthStatus
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function record(string $key, ?bool $ok, ?string $detail = null, ?array $data = null): void
    {
        DB::table('health_status')->upsert([[
            'key' => mb_substr($key, 0, 150), 'ok' => $ok, 'detail' => $detail,
            'data' => $data === null ? null : json_encode($data), 'recorded_at' => now(),
        ]], ['key'], ['ok', 'detail', 'data', 'recorded_at']);
    }

    /**
     * @return array{ok: bool|null, detail: string|null, data: array<string, mixed>|null, at: CarbonImmutable}|null
     */
    public static function get(string $key): ?array
    {
        $row = DB::table('health_status')->where('key', $key)->first();

        return $row === null ? null : self::row($row);
    }

    /**
     * @return array<string, array{ok: bool|null, detail: string|null, data: array<string, mixed>|null, at: CarbonImmutable}>
     */
    public static function prefixed(string $prefix): array
    {
        return DB::table('health_status')->where('key', 'like', addcslashes($prefix, '%_\\').'%')->orderBy('key')->get()
            ->mapWithKeys(fn (\stdClass $row) => [(string) $row->key => self::row($row)])->all();
    }

    /**
     * @return array{ok: bool|null, detail: string|null, data: array<string, mixed>|null, at: CarbonImmutable}
     */
    private static function row(\stdClass $row): array
    {
        $data = is_string($row->data ?? null) ? json_decode($row->data, true) : null;

        return [
            'ok' => $row->ok === null ? null : (bool) $row->ok,
            'detail' => $row->detail === null ? null : (string) $row->detail,
            'data' => is_array($data) ? $data : null,
            'at' => CarbonImmutable::parse((string) $row->recorded_at),
        ];
    }
}
