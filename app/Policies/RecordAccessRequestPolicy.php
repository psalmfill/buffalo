<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Jurisdiction\AccessRequestApprovers;

/**
 * Cross-jurisdiction access requests (Phase 3C). Seen by the requester,
 * the owning state's approvers and national oversight covering the owning
 * state; decided per AccessRequestApprovers; cancelled by the requester
 * while open.
 */
final class RecordAccessRequestPolicy
{
    public function __construct(private readonly AccessRequestApprovers $approvers) {}

    public function viewAny(User $actor): bool
    {
        return ! $actor->isAccessBlocked() && (
            $actor->checkPermissionTo(Permission::AccessRequestsCreate->value)
            || $actor->checkPermissionTo(Permission::AccessRequestsDecide->value)
            || $actor->checkPermissionTo(Permission::AccessRequestsOversee->value)
        );
    }

    public function create(User $actor): bool
    {
        return ! $actor->isAccessBlocked() && $actor->checkPermissionTo(Permission::AccessRequestsCreate->value);
    }

    public function view(User $actor, RecordAccessRequest $request): bool
    {
        return $this->viewAny($actor) && (
            $actor->id === $request->requester_id
            || $this->approvers->isStateApprover($actor, $request->owning_state_id)
            || ($actor->getRole() === Role::StateDirector && $actor->state_id === $request->owning_state_id && ! $actor->isAccessBlocked())
            || $this->approvers->isNationalOverseer($actor, $request->owning_state_id)
        );
    }

    public function decide(User $actor, RecordAccessRequest $request): bool
    {
        return $this->approvers->canDecide($actor, $request);
    }

    public function cancel(User $actor, RecordAccessRequest $request): bool
    {
        return $actor->id === $request->requester_id && $request->status->isOpen() && ! $actor->isAccessBlocked();
    }
}
