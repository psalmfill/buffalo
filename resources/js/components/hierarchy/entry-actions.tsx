import { Button } from '@/components/ui/button';
import type { HierarchyEntry } from '@/types/hierarchy';

/** Rename and deactivate or reactivate buttons of one entry. */
export default function EntryActions({
    entry,
    onRename,
    onToggle,
}: {
    entry: HierarchyEntry;
    onRename: () => void;
    onToggle: () => void;
}) {
    return (
        <>
            <Button
                variant="ghost"
                size="sm"
                data-test={`rename-${entry.code}`}
                onClick={onRename}
            >
                Rename
            </Button>
            <Button
                variant="ghost"
                size="sm"
                data-test={`toggle-${entry.code}`}
                onClick={onToggle}
            >
                {entry.is_active ? 'Deactivate' : 'Reactivate'}
            </Button>
        </>
    );
}
