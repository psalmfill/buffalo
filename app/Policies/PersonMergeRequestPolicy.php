<?php

namespace App\Policies;

use App\Enums\MergeRequestStatus;
use App\Enums\Permission;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Merge requests (Phase 10A): raised by LGA chiefs and Deputy State
 * Directors, listed for state and national users, decided (executed or
 * declined) by holders of persons.merge in the request's state.
 */
final class PersonMergeRequestPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsDuplicatesView->value);
    }

    public function view(User $actor, PersonMergeRequest $request): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->canAccessOwn($actor, $request);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsMergeRequest->value);
    }

    /** Execute or decline. */
    public function decide(User $actor, PersonMergeRequest $request): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsMerge->value)
            && $request->status === MergeRequestStatus::Pending
            && $this->jurisdiction->canAccessOwn($actor, $request);
    }
}
