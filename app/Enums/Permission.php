<?php

namespace App\Enums;

/**
 * Every permission name used in the application. Always reference
 * permissions through this enum, never as string literals. Which roles hold
 * which permissions is defined in RolesAndPermissionsSeeder.
 */
enum Permission: string
{
    // Users (Phase 1)
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    // Audit (Phase 1)
    case AuditView = 'audit.view';
    case LoginHistoryView = 'login_history.view';

    // System administration (Phase 1)
    case SystemHorizon = 'system.horizon';
    case SystemSettings = 'system.settings';

    // Reference data (Phase 2A): configuration, not intelligence
    case ReferenceDataView = 'reference_data.view';
    case ReferenceDataManage = 'reference_data.manage';
}
