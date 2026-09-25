import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import CommunityImportController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityImportController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { ImportRow } from '@/types/local-government';

type Props = {
    lga: { ulid: string; name: string; state: string };
    rows: ImportRow[];
    summary: { new: number; duplicate: number; error: number };
    threshold: number;
};

const STATUS = {
    new: { label: 'New', variant: 'default' },
    duplicate: { label: 'Possible duplicate', variant: 'outline' },
    error: { label: 'Error', variant: 'destructive' },
} as const;

/**
 * Every row of the upload, classified. New rows are imported; possible
 * duplicates only when ticked; errors never.
 */
export default function CommunityImportPreview({
    lga,
    rows,
    summary,
    threshold,
}: Props) {
    const [include, setInclude] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const count = summary.new + include.length;

    const confirm = () =>
        router.post(
            CommunityImportController.store().url,
            { lga: lga.ulid, rows, include },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <>
            <Head title="Import preview" />
            <div className="space-y-6 p-4">
                <Heading
                    title={`Import preview — ${lga.name} LGA`}
                    description={`${lga.state} State. Nothing has been imported yet.`}
                />

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Badge>{summary.new} new</Badge>
                    <Badge variant="outline">
                        {summary.duplicate} possible duplicates
                    </Badge>
                    <Badge variant="destructive">
                        {summary.error} with errors
                    </Badge>
                    <span className="text-muted-foreground">
                        New rows are imported. Tick a possible duplicate to
                        import it anyway. Rows with errors are skipped.
                    </span>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Line</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">Ward</th>
                                <th className="px-3 py-2 font-medium">
                                    Location
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Details
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y align-top">
                            {rows.map((row) => (
                                <tr
                                    key={row.line}
                                    className={cn(
                                        row.status === 'error' &&
                                            'bg-destructive/5',
                                    )}
                                    data-status={row.status}
                                >
                                    <td className="px-3 py-2 tabular-nums">
                                        {row.line}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge
                                            variant={STATUS[row.status].variant}
                                        >
                                            {STATUS[row.status].label}
                                        </Badge>
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.name || '—'}
                                        {row.other_names.length > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                Also:{' '}
                                                {row.other_names.join(', ')}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.type || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.ward_code || '—'}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {row.latitude
                                            ? `${row.latitude}, ${row.longitude}`
                                            : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.status === 'error' && (
                                            <ul className="list-inside list-disc text-destructive">
                                                {row.errors.map((error) => (
                                                    <li key={error}>{error}</li>
                                                ))}
                                            </ul>
                                        )}
                                        {row.status === 'duplicate' &&
                                            row.match && (
                                                <label className="flex items-start gap-2">
                                                    <Checkbox
                                                        checked={include.includes(
                                                            row.line,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setInclude(
                                                                checked === true
                                                                    ? [
                                                                          ...include,
                                                                          row.line,
                                                                      ]
                                                                    : include.filter(
                                                                          (
                                                                              line,
                                                                          ) =>
                                                                              line !==
                                                                              row.line,
                                                                      ),
                                                            )
                                                        }
                                                        aria-label={`Import line ${row.line} anyway`}
                                                    />
                                                    <span>
                                                        Similar to{' '}
                                                        <Link
                                                            href={CommunityController.show(
                                                                row.match.ulid,
                                                            )}
                                                            className="font-medium underline"
                                                            target="_blank"
                                                        >
                                                            {row.match.name}
                                                        </Link>{' '}
                                                        (
                                                        {Math.round(
                                                            row.match
                                                                .similarity *
                                                                100,
                                                        )}
                                                        % similar). Import
                                                        anyway
                                                    </span>
                                                </label>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        onClick={confirm}
                        disabled={processing || count === 0}
                    >
                        {processing && <Spinner />}
                        Import {count}{' '}
                        {count === 1 ? 'community' : 'communities'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={CommunityImportController.create({
                                query: { lga: lga.ulid },
                            })}
                        >
                            Upload another file
                        </Link>
                    </Button>
                    {rows.length > threshold && (
                        <span className="text-sm text-muted-foreground">
                            This import will run in the background.
                        </span>
                    )}
                </div>
            </div>
        </>
    );
}

CommunityImportPreview.layout = {
    breadcrumbs: [
        {
            title: 'Import communities',
            href: CommunityImportController.create(),
        },
    ],
};
