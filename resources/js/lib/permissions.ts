import type { Permission } from '@/types/auth';

/**
 * Whether the permission list grants `permission`. For showing and hiding
 * interface elements only: hiding a link is never a security control, and
 * every route is authorised on the server.
 */
export function can(
    permissions: readonly Permission[],
    permission: Permission | null | undefined,
): boolean {
    return permission == null || permissions.includes(permission);
}
