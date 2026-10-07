import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/time';
import type { OnboardingRow } from '@/types/rollout';

type Props = {
    rows: OnboardingRow[];
    /** Split "setup pending" into "no link" and "link issued". */
    detailed?: boolean;
    /** Extra cell per row (the Rollout page's switch). */
    action?: (row: OnboardingRow) => ReactNode;
};

/**
 * Onboarding progress per state, with each state's rollout status
 * (Phase 9B.2/9B.3). National users are shown first; they are never
 * affected by rollout.
 */
export default function OnboardingTable({
    rows,
    detailed = false,
    action,
}: Props) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm" data-test="onboarding-table">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 font-medium">State</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 text-right font-medium">
                            Users
                        </th>
                        {detailed ? (
                            <>
                                <th className="px-3 py-2 text-right font-medium">
                                    No setup link
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Link issued
                                </th>
                            </>
                        ) : (
                            <th className="px-3 py-2 text-right font-medium">
                                Setup pending
                            </th>
                        )}
                        <th className="px-3 py-2 text-right font-medium">
                            2FA not enrolled
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Ready
                        </th>
                        {action && <th className="px-3 py-2" />}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((row) => (
                        <tr key={row.state?.ulid ?? 'national'}>
                            <td className="px-3 py-2">
                                {row.state?.name ?? 'National and system'}
                            </td>
                            <td className="px-3 py-2">
                                {row.state === null ? (
                                    <span className="text-muted-foreground">
                                        Always active
                                    </span>
                                ) : row.state.is_live ? (
                                    <Badge
                                        className="bg-emerald-600 text-white"
                                        title={
                                            row.state.went_live_at
                                                ? `Live since ${formatDateTime(row.state.went_live_at)}`
                                                : undefined
                                        }
                                    >
                                        Live
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">Not live</Badge>
                                )}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {row.total}
                            </td>
                            {detailed ? (
                                <>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.no_link}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.link_issued}
                                    </td>
                                </>
                            ) : (
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {row.no_link + row.link_issued}
                                </td>
                            )}
                            <td className="px-3 py-2 text-right tabular-nums">
                                {row.no_2fa}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {row.ready}
                            </td>
                            {action && (
                                <td className="px-3 py-2 text-right">
                                    {action(row)}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
