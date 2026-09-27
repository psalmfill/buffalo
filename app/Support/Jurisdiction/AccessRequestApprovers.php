<?php

namespace App\Support\Jurisdiction;

use App\Enums\AccessRequestStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\Person;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may decide cross-jurisdiction access requests and revoke grants
 * (Phase 3C, CLAUDE.md section 4 rule 8).
 *
 * - pending: the owning state's State Director, or a Deputy State Director
 *   holding an active delegation for that state;
 * - escalated: also the DG and the DDGs covering the owning state (zones
 *   including it, or no zones);
 * - never the requester; the decider must be cleared for the record's
 *   classification (evaluated through the decider's own scopes).
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction): deciders and
 * delegations are looked up whatever the acting user may see (a Lagos
 * officer's request must reach the Kano State Director). Only users and
 * delegations are read, to decide or to address notifications.
 */
final class AccessRequestApprovers
{
    public function __construct(
        private readonly JurisdictionContext $context,
        private readonly JurisdictionRules $rules,
    ) {}

    public function canDecide(User $user, RecordAccessRequest $request): bool
    {
        if (! $request->status->isOpen() || $user->id === $request->requester_id || $user->isAccessBlocked()) {
            return false;
        }

        $eligible = $this->isStateApprover($user, $request->owning_state_id)
            || ($request->status === AccessRequestStatus::Escalated && $this->isNationalOverseer($user, $request->owning_state_id));

        return $eligible && $this->clearedFor($user, $request);
    }

    /**
     * Revoke a grant: its approver, the owning State Director, a delegated
     * deputy, the DG or a DDG covering the state.
     */
    public function canRevoke(User $user, RecordAccessGrant $grant, int $owningStateId): bool
    {
        if ($grant->revoked_at !== null || $user->isAccessBlocked()) {
            return false;
        }

        return $user->id === $grant->granted_by
            || $this->isStateApprover($user, $owningStateId)
            || $this->isNationalOverseer($user, $owningStateId);
    }

    /**
     * The State Director of the state, or a Deputy with an active
     * delegation for it.
     */
    public function isStateApprover(User $user, int $stateId): bool
    {
        if ($user->isAccessBlocked() || $user->state_id !== $stateId || ! $user->checkPermissionTo(Permission::AccessRequestsDecide->value)) {
            return false;
        }

        return match ($user->getRole()) {
            Role::StateDirector => true,
            Role::DeputyStateDirector => $this->activeDelegation($user, $stateId) !== null,
            default => false,
        };
    }

    /** The DG, or a DDG whose reach covers the state. */
    public function isNationalOverseer(User $user, int $stateId): bool
    {
        if ($user->isAccessBlocked() || ! $user->checkPermissionTo(Permission::AccessRequestsOversee->value)) {
            return false;
        }

        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);

        return match ($restriction->type) {
            RestrictionType::Everything => true,
            RestrictionType::States => in_array($stateId, $restriction->stateIds, true),
            default => false,
        };
    }

    public function activeDelegation(User $deputy, int $stateId): ?ApprovalDelegation
    {
        return ApprovalDelegation::query()
            ->withoutGlobalScope(JurisdictionScope::class)
            ->active()
            ->where('delegate_id', $deputy->id)
            ->where('state_id', $stateId)
            ->first();
    }

    /**
     * Delegations (active or not yet started, not revoked) where the user
     * is either party: they end when the user's posting closes, whoever
     * closes it (an administrator sees no delegations through the scope).
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function openDelegationsInvolving(User $user): Collection
    {
        return ApprovalDelegation::query()
            ->withoutGlobalScope(JurisdictionScope::class)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->where('delegator_id', $user->id)->orWhere('delegate_id', $user->id))
            ->get();
    }

    /**
     * The delegator and delegate (active or not), to notify them whoever
     * acts.
     *
     * @return array{0: User|null, 1: User|null}
     */
    public function delegationParties(ApprovalDelegation $delegation): array
    {
        $users = User::query()->withoutGlobalScope(JurisdictionScope::class)
            ->whereKey([$delegation->delegator_id, $delegation->delegate_id])->get()->keyBy('id');

        return [$users->get($delegation->delegator_id), $users->get($delegation->delegate_id)];
    }

    /**
     * Whether the deputy already has a delegation that is active or yet to
     * start (at most one active per delegate).
     */
    public function hasOpenDelegation(User $deputy): bool
    {
        return ApprovalDelegation::query()
            ->withoutGlobalScope(JurisdictionScope::class)
            ->whereNull('revoked_at')
            ->where('delegate_id', $deputy->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->exists();
    }

    /**
     * Everyone who may decide the request now (for notifications).
     *
     * @return Collection<int, User>
     */
    public function deciders(RecordAccessRequest $request, bool $nationalOnly = false): Collection
    {
        $state = State::query()->find($request->owning_state_id);
        $users = new Collection;

        if (! $nationalOnly) {
            $users = $users->merge($this->users()->role(Role::StateDirector->value)->where('state_id', $request->owning_state_id)->get());

            $delegates = ApprovalDelegation::query()->withoutGlobalScope(JurisdictionScope::class)->active()
                ->where('state_id', $request->owning_state_id)->pluck('delegate_id');
            $users = $users->merge($this->users()->role(Role::DeputyStateDirector->value)->whereKey($delegates)->where('state_id', $request->owning_state_id)->get());
        }

        if ($request->status === AccessRequestStatus::Escalated && $state !== null) {
            $recipients = app(NotificationRecipients::class);
            $users = $users->merge($recipients->resolve(Role::DirectorGeneral))->merge($recipients->resolve(Role::DeputyDirectorGeneral, $state));
        }

        return $users->unique('id')->reject(fn (User $u) => $u->id === $request->requester_id || $u->isAccessBlocked())->values();
    }

    /**
     * Owners to tell about a state's records (digest): State Directors and
     * delegated deputies.
     *
     * @return Collection<int, User>
     */
    public function stateOwners(int $stateId): Collection
    {
        $delegates = ApprovalDelegation::query()->withoutGlobalScope(JurisdictionScope::class)->active()
            ->where('state_id', $stateId)->pluck('delegate_id');

        return $this->users()
            ->where('state_id', $stateId)
            ->where(fn ($q) => $q->role(Role::StateDirector->value)->orWhereIn('id', $delegates))
            ->get()
            ->reject(fn (User $u) => $u->isAccessBlocked())
            ->values();
    }

    /**
     * The decider must be cleared for the record: evaluated through their
     * own scopes (jurisdiction and classification). Escalated deciders
     * (DG, DDG) see it by jurisdiction; a delegated deputy only up to
     * confidential.
     */
    private function clearedFor(User $user, RecordAccessRequest $request): bool
    {
        return $this->context->actingAs($user, fn (): bool => Person::query()->whereKey($request->subject_id)->exists());
    }

    /**
     * @return Builder<User>
     */
    private function users(): Builder
    {
        return User::query()->withoutGlobalScope(JurisdictionScope::class)->where('is_active', true);
    }
}
