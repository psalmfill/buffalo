import { AlertTriangle, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import type {
    CasualtyComparisonRow,
    ConsolidationItem,
    ConsolidationPreview as Preview,
} from '@/types/incidents';

const KIND_LABELS: Record<ConsolidationItem['kind'], string> = {
    evidence: 'Evidence',
    update: 'Follow-up',
    person: 'Person',
    group: 'Criminal group',
    agency: 'Agency',
    property: 'Property',
    flashpoint: 'Flashpoint link',
    threat: 'Threat report link',
    access_request: 'Access request',
    arrest: 'Arrest',
};

const OUTCOMES: Record<
    ConsolidationItem['outcome'],
    { label: string; tone: string }
> = {
    move: { label: 'Moved', tone: 'text-emerald-700 dark:text-emerald-400' },
    copy: { label: 'Copied', tone: 'text-emerald-700 dark:text-emerald-400' },
    add: { label: 'Added', tone: 'text-emerald-700 dark:text-emerald-400' },
    repoint: {
        label: 'Now cites the original',
        tone: 'text-emerald-700 dark:text-emerald-400',
    },
    skip: {
        label: 'Already on the original (notes added)',
        tone: 'text-muted-foreground',
    },
    not_consolidated: {
        label: 'Not consolidated',
        tone: 'text-amber-700 dark:text-amber-400',
    },
    left: {
        label: 'Left on the duplicate',
        tone: 'text-amber-700 dark:text-amber-400',
    },
};

function CasualtyTable({
    title,
    rows,
}: {
    title: string;
    rows: CasualtyComparisonRow[];
}) {
    return (
        <div>
            <p className="mb-1 text-xs font-medium">{title}</p>
            {rows.length === 0 ? (
                <p className="text-xs text-muted-foreground">None recorded.</p>
            ) : (
                <ul className="space-y-0.5 text-xs">
                    {rows.map((row) => (
                        <li
                            key={row.ulid}
                            className="flex justify-between gap-2"
                        >
                            <span>
                                {row.outcome.label} · {row.group.label}
                                {row.agency && ` (${row.agency.name})`}
                            </span>
                            <span className="font-medium">{row.total}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Phase 10B.4: what consolidating the duplicate into the original will do,
 * item by item, with both incidents' casualties side by side (never summed)
 * and the irreversibility notice.
 */
export default function ConsolidationPreview({
    preview,
}: {
    preview: Preview;
}) {
    if (!preview.allowed) {
        return (
            <p className="rounded-md border border-amber-300 p-3 text-sm">
                {preview.refusal}
            </p>
        );
    }

    const duplicate = preview.duplicate.reference_no ?? 'this incident';
    const original = preview.original.reference_no ?? 'the original';

    return (
        <section
            className="space-y-3 rounded-lg border p-3 text-sm"
            aria-label="Consolidation preview"
        >
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="text-left text-muted-foreground">
                        <tr>
                            <th className="py-1 pr-2 font-medium">
                                On {duplicate}
                            </th>
                            <th className="py-1 font-medium">On {original}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {preview.items.length === 0 && (
                            <tr>
                                <td
                                    colSpan={2}
                                    className="py-2 text-muted-foreground"
                                >
                                    Nothing to consolidate apart from the
                                    casualty figures below.
                                </td>
                            </tr>
                        )}
                        {preview.items.map((item, index) => (
                            <tr key={`${item.kind}-${index}`}>
                                <td className="py-1 pr-2 align-top">
                                    <span className="font-medium">
                                        {KIND_LABELS[item.kind]}
                                    </span>
                                    {item.label && `: ${item.label}`}
                                    {item.detail &&
                                        item.outcome !== 'not_consolidated' &&
                                        item.outcome !== 'left' && (
                                            <span className="block text-muted-foreground">
                                                {item.detail}
                                            </span>
                                        )}
                                </td>
                                <td
                                    className={cn(
                                        'py-1 align-top',
                                        OUTCOMES[item.outcome].tone,
                                    )}
                                >
                                    {OUTCOMES[item.outcome].label}
                                    {(item.outcome === 'not_consolidated' ||
                                        item.outcome === 'left') &&
                                        item.detail &&
                                        ` (${item.detail})`}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="space-y-2 rounded-md bg-muted/50 p-2">
                <p className="text-xs">
                    Casualties are never moved or added up. The duplicate's
                    figures stay on it (an invalid incident is left out of
                    statistics). If they are better, amend the original's
                    figures.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <CasualtyTable
                        title={duplicate}
                        rows={preview.casualties.duplicate}
                    />
                    <CasualtyTable
                        title={original}
                        rows={preview.casualties.original}
                    />
                </div>
                {preview.amendUrl && (
                    <a
                        href={preview.amendUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-xs hover:underline"
                    >
                        Amend the original <ExternalLink className="size-3" />
                    </a>
                )}
            </div>

            <p className="flex items-start gap-2 text-xs font-medium text-amber-700 dark:text-amber-400">
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                This cannot be undone: afterwards this incident is invalid and
                locked, and the moved and copied content belongs to the
                original.
            </p>
        </section>
    );
}
