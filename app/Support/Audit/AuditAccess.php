<?php

namespace App\Support\Audit;

use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Models\User;

/**
 * Who may read audit trails. The audit and login-history viewers have no
 * jurisdiction filtering yet, so on top of the permission the viewer must
 * have system or national reach. If a state- or LGA-level role is ever
 * given audit.view or login_history.view, the viewers refuse until
 * jurisdiction filtering is added.
 */
final class AuditAccess
{
    public static function canViewAudit(User $user): bool
    {
        return $user->checkPermissionTo(Permission::AuditView->value) && self::hasUnfilteredReach($user);
    }

    public static function canViewLoginHistory(User $user): bool
    {
        return $user->checkPermissionTo(Permission::LoginHistoryView->value) && self::hasUnfilteredReach($user);
    }

    public static function hasUnfilteredReach(User $user): bool
    {
        return in_array($user->jurisdiction_level, [JurisdictionLevel::System, JurisdictionLevel::National], true);
    }
}
