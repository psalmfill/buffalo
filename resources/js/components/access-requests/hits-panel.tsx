import { ShieldQuestion } from 'lucide-react';
import { useState } from 'react';
import RequestAccessDialog from '@/components/access-requests/request-access-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AccessRequestOptions, Hit } from '@/types/access-requests';

export const LIMIT_MESSAGE =
    'Daily cross-jurisdiction search limit reached. Contact your State Director.';

/**
 * Records in other jurisdictions: the owning state, the category and the
 * match strength only, with "Request access".
 */
export default function HitsPanel({
    hits,
    limitReached,
    options,
    emptyText = 'No matching records in other jurisdictions.',
    incidentUlid = null,
}: {
    hits: Hit[];
    limitReached: boolean;
    options: AccessRequestOptions;
    emptyText?: string;
    /** Cite this incident with any access request made from here. */
    incidentUlid?: string | null;
}) {
    const [requesting, setRequesting] = useState<Hit | null>(null);

    return (
        <section
            aria-label="Records in other jurisdictions"
            data-test="cross-jurisdiction-hits"
            className="space-y-3 rounded-lg border border-dashed p-4"
        >
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <ShieldQuestion className="size-4" /> Records in other
                jurisdictions
            </h2>
            {limitReached ? (
                <p className="text-sm text-destructive">{LIMIT_MESSAGE}</p>
            ) : hits.length === 0 ? (
                <p className="text-sm text-muted-foreground">{emptyText}</p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {hits.map((hit, index) => (
                        <li
                            key={index}
                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                        >
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {hit.state} State
                                </span>
                                <span className="text-muted-foreground">·</span>
                                <span>{hit.category}</span>
                                <Badge
                                    variant={
                                        hit.strength === 'Record found'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {hit.strength}
                                </Badge>
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setRequesting(hit)}
                            >
                                Request access
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {requesting && (
                <RequestAccessDialog
                    hit={requesting}
                    options={options}
                    onClose={() => setRequesting(null)}
                    incidentUlid={incidentUlid}
                />
            )}
        </section>
    );
}
