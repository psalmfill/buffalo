<?php

namespace App\Support\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The attribution of the boundaries in use (the latest import per level),
 * shown on every map. Cached; forgotten after each import.
 */
final class BoundaryAttribution
{
    private const CACHE_KEY = 'buffalo.boundary_attribution';

    public static function current(): ?string
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): string {
            $latest = DB::table('boundary_sources')
                ->select('level', 'attribution')
                ->distinct('level')
                ->orderBy('level')
                ->orderByDesc('imported_at')
                ->orderByDesc('id')
                ->get();

            return $latest->pluck('attribution')->unique()->implode(' · ');
        }) ?: null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
