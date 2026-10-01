<?php

namespace App\Support\Map;

use App\Enums\JurisdictionLevel;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache of aggregated incident layers (Phase 6C), in Valkey for
 * `buffalo.map.cache_seconds`.
 *
 * Keys hold the layer, the viewer's scope signature, the filters, the tile
 * or area and the zoom bucket, plus a data version: incident events bump
 * the version of their state (and the national one), so a cached layer is
 * never served after an incident in its area was submitted or changed
 * status. A boundary import bumps a global version (all layers). National viewers follow the national version; others their
 * state's.
 */
final class LayerCache
{
    private const PREFIX = 'map:layer:';

    /**
     * @template T
     *
     * @param  array<string, mixed>  $filters  canonical filter values
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $layer, User $user, array $filters, string $area, Closure $compute): mixed
    {
        $key = self::PREFIX.$layer.':'.hash('sha256', (string) json_encode([
            ScopeSignature::for($user),
            $this->version($user),
            $filters,
            $area,
        ]));

        return Cache::remember($key, (int) config('buffalo.map.cache_seconds'), $compute);
    }

    /** Called when an incident in the state is submitted or changes status. */
    public static function bump(int $stateId): void
    {
        foreach (["map:version:state:{$stateId}", 'map:version:national'] as $key) {
            Cache::forever($key, ((int) Cache::get($key, 0)) + 1);
        }
    }

    /** Called after a boundary import: every cached layer is outdated. */
    public static function bumpAll(): void
    {
        Cache::forever('map:version:global', ((int) Cache::get('map:version:global', 0)) + 1);
    }

    private function version(User $user): string
    {
        $global = 'g'.(int) Cache::get('map:version:global', 0);

        return $global.($user->jurisdiction_level === JurisdictionLevel::National || $user->state_id === null
            ? 'n'.(int) Cache::get('map:version:national', 0)
            : 's'.(int) Cache::get("map:version:state:{$user->state_id}", 0));
    }
}
