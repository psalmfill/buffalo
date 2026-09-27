<?php

namespace App\Policies;

use App\Models\RecordAccessGrant;
use App\Models\User;
use App\Support\Jurisdiction\AccessRequestApprovers;

/**
 * Revoking a grant (Phase 3C): its approver, the owning State Director, a
 * delegated deputy, the DG or a DDG covering the owning state.
 */
final class RecordAccessGrantPolicy
{
    public function __construct(private readonly AccessRequestApprovers $approvers) {}

    public function revoke(User $actor, RecordAccessGrant $grant): bool
    {
        return $grant->isActive() && $this->approvers->canRevoke($actor, $grant, $grant->request->owning_state_id);
    }
}
