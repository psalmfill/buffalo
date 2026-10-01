<?php

namespace App\Support\Jurisdiction;

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The states and LGAs a user may SELECT in forms for intelligence records
 * (Phase 2 onwards). Only active places. Super admins get none: they have no
 * access to intelligence content (CLAUDE.md section 4).
 *
 * Hierarchy models are not scoped, so this is what keeps form options
 * inside the user's jurisdiction.
 */
final class JurisdictionOptions
{
    public function __construct(private readonly JurisdictionRules $rules) {}

    /**
     * @return Collection<int, State>
     */
    public function states(User $user): Collection
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);
        $query = State::query()->active()->orderBy('name');

        return match ($restriction->type) {
            RestrictionType::Everything => $query->get(),
            RestrictionType::States => $query->whereKey($restriction->stateIds)->get(),
            RestrictionType::Lga => $query->whereKey(Lga::query()->whereKey($restriction->lgaId)->value('state_id'))->get(),
            RestrictionType::Nothing => new Collection,
        };
    }

    /**
     * States a user administrator may ASSIGN to accounts (user management),
     * as opposed to select for intelligence records. Only users holding
     * users.manage get options: they manage accounts everywhere.
     *
     * @return Collection<int, State>
     */
    public function assignableStates(User $actor): Collection
    {
        return $actor->checkPermissionTo(Permission::UsersManage->value) && ! $actor->isAccessBlocked()
            ? State::query()->active()->orderBy('name')->get()
            : new Collection;
    }

    /**
     * @return Collection<int, Lga>
     */
    public function assignableLgas(User $actor): Collection
    {
        return $actor->checkPermissionTo(Permission::UsersManage->value) && ! $actor->isAccessBlocked()
            ? Lga::query()->active()->with('state:id,ulid')->orderBy('name')->get()
            : new Collection;
    }

    /**
     * @param  State|null  $state  narrow to one state (must itself be selectable)
     * @return Collection<int, Lga>
     */
    public function lgas(User $user, ?State $state = null): Collection
    {
        return $this->lgaQuery($user)
            ->active()
            ->orderBy('name')
            ->when($state !== null, fn (Builder $q) => $q->where('state_id', $state?->id))
            ->get();
    }

    /**
     * LGAs (reference data, not scoped) within the user's jurisdiction, as a
     * query: the same rules as JurisdictionScope, for pages that list or
     * open LGAs. Super admins get none (LGA pages are intelligence).
     *
     * @return Builder<Lga>
     */
    public function lgaQuery(User $user): Builder
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);
        $query = Lga::query();

        return match ($restriction->type) {
            RestrictionType::Everything => $query,
            RestrictionType::States => $query->whereIn($query->qualifyColumn('state_id'), $restriction->stateIds),
            RestrictionType::Lga => $query->whereKey($restriction->lgaId),
            RestrictionType::Nothing => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Senatorial districts (reference data, not scoped) within the user's
     * jurisdiction, as a query: those of their states, or the district of
     * an LGA chief's LGA. Super admins get none.
     *
     * @return Builder<SenatorialDistrict>
     */
    public function districtQuery(User $user): Builder
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);
        $query = SenatorialDistrict::query();

        return match ($restriction->type) {
            RestrictionType::Everything => $query,
            RestrictionType::States => $query->whereIn($query->qualifyColumn('state_id'), $restriction->stateIds),
            RestrictionType::Lga => $query->whereKey(Lga::query()->whereKey($restriction->lgaId)->select('senatorial_district_id')),
            RestrictionType::Nothing => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Whether the LGA lies within the user's jurisdiction.
     */
    public function coversLga(User $user, Lga $lga): bool
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);

        return match ($restriction->type) {
            RestrictionType::Everything => true,
            RestrictionType::States => in_array($lga->state_id, $restriction->stateIds, true),
            RestrictionType::Lga => $lga->id === $restriction->lgaId,
            RestrictionType::Nothing => false,
        };
    }

    /**
     * Whether any part of $state is within $user's jurisdiction (an LGA chief
     * covers their own LGA's state), e.g. for the state incident channel.
     */
    public function coversState(User $user, State $state): bool
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);

        return match ($restriction->type) {
            RestrictionType::Everything => true,
            RestrictionType::States => in_array($state->id, $restriction->stateIds, true),
            RestrictionType::Lga => Lga::query()->whereKey($restriction->lgaId)->value('state_id') === $state->id,
            RestrictionType::Nothing => false,
        };
    }
}
