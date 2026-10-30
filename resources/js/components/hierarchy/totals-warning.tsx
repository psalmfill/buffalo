import { TriangleAlert } from 'lucide-react';

/** The import's expected-count check, as a warning (never a block). */
export default function TotalsWarning({ warnings }: { warnings: string[] }) {
    if (warnings.length === 0) {
        return null;
    }

    return (
        <div
            role="status"
            data-test="hierarchy-count-warning"
            className="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-1">
                {warnings.map((warning) => (
                    <p key={warning}>{warning}</p>
                ))}
            </div>
        </div>
    );
}
