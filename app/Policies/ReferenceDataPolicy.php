<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReferenceModel;
use App\Models\User;

/**
 * Who may read and manage reference data (all six lookup tables).
 *
 * - reference_data.view (every role): list and view values.
 * - reference_data.manage (super admin, DG): create, edit, activate and
 *   deactivate values, and edit incident type detail fields.
 *
 * Reference data is configuration, not intelligence: it is not
 * jurisdiction-scoped. Values are never deleted, so there is no delete
 * ability. Blocked actors are denied by the Gate::before hook.
 */
final class ReferenceDataPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ReferenceDataView->value);
    }

    public function view(User $actor, ReferenceModel $value): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User $actor, ReferenceModel $value): bool
    {
        return $this->manages($actor);
    }

    public function delete(User $actor, ReferenceModel $value): bool
    {
        return false;
    }

    private function manages(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ReferenceDataManage->value);
    }
}
