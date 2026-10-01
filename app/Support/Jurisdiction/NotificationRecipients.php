<?php

namespace App\Support\Jurisdiction;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves who receives a role-targeted notification (NotifyRole).
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). Recipients must
 * be found whatever the sender may see: an LGA chief's incident alert must
 * reach the state director and super admins. Only active, non-deleted users
 * are returned, and only to deliver notifications, whose payloads never
 * contain sensitive content (BuffaloNotification).
 *
 * Which users of $role a place covers:
 * - no place, super admin or DG: all of them;
 * - DDG: national DDGs, plus those whose zones include the place's zone;
 * - state director / deputy: those of the place's state;
 * - LGA chief: of that LGA, or of every LGA in that state.
 */
final class NotificationRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(Role $role, State|Lga|null $place = null): Collection
    {
        $stateId = match (true) {
            $place instanceof Lga => $place->state_id,
            $place instanceof State => $place->id,
            default => null,
        };

        $query = User::query()
            ->withoutGlobalScope(JurisdictionScope::class)
            ->role($role->value)
            ->where('is_active', true);

        if ($place === null || in_array($role, [Role::SuperAdmin, Role::DirectorGeneral], true)) {
            return $query->get();
        }

        return match ($role) {
            Role::DeputyDirectorGeneral => $query->where(function (Builder $q) use ($stateId): void {
                $zoneId = State::query()->whereKey($stateId)->value('geopolitical_zone_id');

                $q->whereDoesntHave('zones')
                    ->orWhereHas('zones', fn (Builder $zones) => $zones->whereKey($zoneId));
            })->get(),
            Role::StateDirector, Role::DeputyStateDirector => $query->where('state_id', $stateId)->get(),
            Role::LgaSecurityChief => $place instanceof Lga
                ? $query->where('lga_id', $place->id)->get()
                : $query->where('state_id', $stateId)->get(),
        };
    }

    /**
     * Named users (e.g. an incident's reporting officer and creator),
     * active only, whatever the sender may see.
     *
     * @param  list<int|null>  $ids
     * @return Collection<int, User>
     */
    public function users(array $ids): Collection
    {
        return User::query()
            ->withoutGlobalScope(JurisdictionScope::class)
            ->whereKey(array_values(array_filter($ids, 'is_int')))
            ->where('is_active', true)
            ->get();
    }
}
