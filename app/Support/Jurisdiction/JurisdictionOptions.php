<?php

namespace App\Support\Jurisdiction;

use App\Enums\Permission;
use App\Models\Lga;
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
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);
        $query = Lga::query()->active()->orderBy('name')
            ->when($state !== null, fn (Builder $q) => $q->where('state_id', $state?->id));

        return match ($restriction->type) {
            RestrictionType::Everything => $query->get(),
            RestrictionType::States => $query->whereIn('state_id', $restriction->stateIds)->get(),
            RestrictionType::Lga => $query->whereKey($restriction->lgaId)->get(),
            RestrictionType::Nothing => new Collection,
        };
    }
}
