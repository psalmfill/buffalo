import { Link } from '@inertiajs/react';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import type { GroupMember } from '@/types/political';

/** Criminal groups the person belongs to (groups the viewer can see). */
export default function GroupMembershipsPanel({
    memberships,
}: {
    memberships: GroupMember[];
}) {
    if (memberships.length === 0) {
        return (
            <div className="rounded-lg border">
                <EmptyState
                    title="No group memberships"
                    description="Members are added from a criminal group's page."
                />
            </div>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Group</th>
                        <th className="px-3 py-2 font-medium">Type</th>
                        <th className="px-3 py-2 font-medium">Role</th>
                        <th className="px-3 py-2 font-medium">Membership</th>
                        <th className="px-3 py-2 font-medium">Since</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {memberships.map((m) =>
                        m.group ? (
                            <tr key={m.ulid}>
                                <td className="px-3 py-2">
                                    <Link
                                        href={CriminalGroupController.show(
                                            m.group.ulid,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {m.group.name}
                                    </Link>{' '}
                                    <ClassificationBadge
                                        level={m.group.classification.value}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    {m.group.type.label}
                                </td>
                                <td className="px-3 py-2">
                                    {m.role_in_group ?? '—'}
                                </td>
                                <td className="px-3 py-2">
                                    {m.membership_status.label}
                                </td>
                                <td className="px-3 py-2">{m.since ?? '—'}</td>
                            </tr>
                        ) : null,
                    )}
                </tbody>
            </table>
        </div>
    );
}
