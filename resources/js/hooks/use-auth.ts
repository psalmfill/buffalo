import { usePage } from '@inertiajs/react';
import { can as grants } from '@/lib/permissions';
import type { Permission, User } from '@/types/auth';

/**
 * The signed-in user and their permissions. `can()` controls visibility
 * only; every route is authorised on the server.
 */
export function useAuth(): {
    user: User | null;
    permissions: Permission[];
    can: (permission: Permission) => boolean;
} {
    const { user, permissions } = usePage().props.auth;

    return {
        user,
        permissions,
        can: (permission) => grants(permissions, permission),
    };
}

/**
 * The signed-in user, for components that only render inside the
 * authenticated app shell.
 */
export function useCurrentUser(): User {
    const { user } = useAuth();

    if (user === null) {
        throw new Error('useCurrentUser() used outside an authenticated page.');
    }

    return user;
}
