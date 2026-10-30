import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/time';
import type { HierarchyEntry } from '@/types/hierarchy';

/** Active or deactivated, and who created it on screen (with the reason). */
export default function EntryStatus({ entry }: { entry: HierarchyEntry }) {
    return (
        <div className="flex flex-wrap items-center gap-1">
            <Badge variant={entry.is_active ? 'default' : 'secondary'}>
                {entry.is_active ? 'Active' : 'Deactivated'}
            </Badge>
            {entry.created_on_screen && (
                <Badge
                    variant="outline"
                    title={[
                        entry.created_by && `Created by ${entry.created_by}`,
                        entry.created_at && formatDateTime(entry.created_at),
                        entry.created_reason &&
                            `Reason: ${entry.created_reason}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                >
                    Created on screen
                </Badge>
            )}
        </div>
    );
}
