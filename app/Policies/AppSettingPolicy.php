<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Settings changed in the UI. The theme colours (Phase 11A.3): super
 * admins and the Director General (theme.manage).
 */
class AppSettingPolicy
{
    public function manageTheme(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ThemeManage->value);
    }
}
