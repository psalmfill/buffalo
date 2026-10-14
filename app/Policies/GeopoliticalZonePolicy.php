<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\GeopoliticalZone;
use App\Models\User;
use App\Support\Lens\StateLens;

/**
 * Geopolitical zones are reference data; national users may view a zone's
 * dashboard or choose it as their lens (Phase 11A.2) when any of its states
 * is within their reach.
 */
class GeopoliticalZonePolicy
{
    public function viewDashboard(User $user, GeopoliticalZone $zone): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value) && app(StateLens::class)->canChooseZone($user, $zone);
    }
}
