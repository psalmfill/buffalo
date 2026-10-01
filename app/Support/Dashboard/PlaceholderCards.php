<?php

namespace App\Support\Dashboard;

use App\Models\Flashpoint;
use App\Models\User;

/**
 * The operational cards on the national, state and LGA dashboards. Until
 * their phase delivers the data they show "Available from Phase N"; a phase
 * replaces a placeholder by giving its card real content (`count`, `href`).
 *
 * Phase 6 delivered flashpoints: the card counts the active and monitoring
 * flashpoints and blackspots the user can see (null without the
 * permission, which keeps the placeholder).
 */
final class PlaceholderCards
{
    /**
     * @return list<array{key: string, title: string, scope: string, availableFromPhase: int, count: int|null, href: string|null}>
     */
    public static function for(string $scope, ?User $user = null): array
    {
        $flashpoints = $user !== null && $user->can('viewAny', Flashpoint::class)
            ? Flashpoint::query()->watched()->count()
            : null;

        return [
            ['key' => 'flashpoints', 'title' => 'Active flashpoints', 'scope' => $scope, 'availableFromPhase' => 6, 'count' => $flashpoints, 'href' => $flashpoints === null ? null : '/flashpoints'],
        ];
    }
}
