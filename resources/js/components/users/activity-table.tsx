import { formatDateTime } from '@/lib/time';
import type { ActivityEntry } from '@/types/users';

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/**
 * Read-only audit entries with old and new values.
 */
export default function ActivityTable({
    entries,
}: {
    entries: ActivityEntry[];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Time</th>
                        <th className="px-3 py-2 font-medium">Event</th>
                        <th className="px-3 py-2 font-medium">By</th>
                        <th className="px-3 py-2 font-medium">Record</th>
                        <th className="px-3 py-2 font-medium">Changes</th>
                    </tr>
                </thead>
                <tbody className="divide-y align-top">
                    {entries.length === 0 && (
                        <tr>
                            <td
                                colSpan={5}
                                className="px-3 py-6 text-center text-muted-foreground"
                            >
                                No entries
                            </td>
                        </tr>
                    )}
                    {entries.map((entry, index) => {
                        const keys = Array.from(
                            new Set([
                                ...Object.keys(entry.old ?? {}),
                                ...Object.keys(entry.new ?? {}),
                            ]),
                        );

                        return (
                            <tr key={`${entry.created_at}-${index}`}>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {formatDateTime(entry.created_at)}
                                </td>
                                <td className="px-3 py-2">
                                    <div className="font-medium">
                                        {entry.event ?? entry.log}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {entry.description}
                                    </div>
                                </td>
                                <td className="px-3 py-2">
                                    {entry.causer?.label ?? 'System'}
                                    {entry.ip_address && (
                                        <div className="text-xs text-muted-foreground">
                                            {entry.ip_address}
                                        </div>
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    {entry.subject_type ?? '—'}
                                    {entry.subject && (
                                        <div className="text-xs text-muted-foreground">
                                            {entry.subject.label}
                                        </div>
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    {keys.length > 0 && (
                                        <table className="text-xs">
                                            <tbody>
                                                {keys.map((key) => (
                                                    <tr key={key}>
                                                        <td className="pr-2 font-medium">
                                                            {key}
                                                        </td>
                                                        <td className="pr-2 text-muted-foreground line-through">
                                                            {formatValue(
                                                                entry.old?.[
                                                                    key
                                                                ],
                                                            )}
                                                        </td>
                                                        <td>
                                                            {formatValue(
                                                                entry.new?.[
                                                                    key
                                                                ],
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                    {Object.keys(entry.properties).length >
                                        0 && (
                                        <pre className="mt-1 max-w-md text-xs whitespace-pre-wrap text-muted-foreground">
                                            {JSON.stringify(
                                                entry.properties,
                                                null,
                                                1,
                                            )}
                                        </pre>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
