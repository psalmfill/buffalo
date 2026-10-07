import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import UserImportController from '@/actions/App/Http/Controllers/Users/UserImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { UserImportRow } from '@/types/onboarding';

type Props = {
    rows: UserImportRow[];
    summary: { new: number; exists: number; error: number };
};

const STATUS = {
    new: { label: 'New', variant: 'default' },
    exists: { label: 'Already exists', variant: 'outline' },
    error: { label: 'Error', variant: 'destructive' },
} as const;

const COLUMNS = [
    'name',
    'email',
    'phone',
    'service_number',
    'rank',
    'role',
    'state_code',
    'lga_code',
    'zone_codes',
] as const;

/**
 * Every row of the upload, classified. Accounts are created only when no
 * row has an error, all in one transaction.
 */
export default function UserImportPreview({ rows, summary }: Props) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const confirm = () =>
        router.post(
            UserImportController.store().url,
            {
                rows: rows.map((row) => ({
                    line: row.line,
                    ...Object.fromEntries(
                        COLUMNS.map((column) => [column, row[column]]),
                    ),
                })),
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    setError(errors.rows ?? Object.values(errors)[0]),
            },
        );

    return (
        <>
            <Head title="Import preview" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Import preview"
                    description="Nothing has been created yet."
                />

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Badge>{summary.new} new</Badge>
                    <Badge variant="outline">
                        {summary.exists} already exist
                    </Badge>
                    <Badge variant="destructive">
                        {summary.error} with errors
                    </Badge>
                    <span className="text-muted-foreground">
                        {summary.error > 0
                            ? 'Correct the rows with errors in the file and upload it again.'
                            : 'New rows become accounts without setup links; existing accounts are skipped.'}
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
                                <th className="px-3 py-2 font-medium">Email</th>
                                <th className="px-3 py-2 font-medium">Role</th>
                                <th className="px-3 py-2 font-medium">
                                    Jurisdiction
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Problems
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
                                        {row.rank && (
                                            <div className="text-xs text-muted-foreground">
                                                {row.rank}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.email || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.role_label || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.place || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.errors.length > 0 && (
                                            <ul className="list-inside list-disc text-destructive">
                                                {row.errors.map((message) => (
                                                    <li key={message}>
                                                        {message}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <InputError message={error} />
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        onClick={confirm}
                        disabled={
                            processing || summary.error > 0 || summary.new === 0
                        }
                        data-test="confirm-import"
                    >
                        {processing && <Spinner />}
                        Create {summary.new}{' '}
                        {summary.new === 1 ? 'account' : 'accounts'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={UserImportController.create()}>
                            Upload another file
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

UserImportPreview.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Import users', href: UserImportController.create() },
    ],
};
