import { Head, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import UserImportController from '@/actions/App/Http/Controllers/Users/UserImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    columns: string[];
    maxRows: number;
    roles: { value: string; label: string }[];
    zones: { code: string; name: string }[];
};

/**
 * Bulk user import (Phase 9B.2): upload a CSV; every row is validated and
 * shown before any account is created.
 */
export default function UserImport({ columns, maxRows, roles, zones }: Props) {
    const form = useForm<{ file: File | null }>({ file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(UserImportController.preview().url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Import users" />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    help={{ topic: 'roles/super-admin', section: 'accounts' }}
                    title="Import users"
                    description="Create many accounts from one CSV file. You will see every row before anything is created. No setup links are sent: issue them afterwards from Onboarding."
                />

                <div className="grid gap-2">
                    <Label htmlFor="file">CSV file</Label>
                    <Input
                        id="file"
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(event) =>
                            form.setData(
                                'file',
                                event.target.files?.[0] ?? null,
                            )
                        }
                        required
                    />
                    <InputError message={form.errors.file} />
                </div>

                <div className="space-y-2 rounded-lg border bg-muted/30 p-4 text-sm">
                    <p>
                        Columns (header row required, any order):{' '}
                        <code className="text-xs">{columns.join(',')}</code>
                    </p>
                    <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                        <li>
                            <code>name</code>, <code>email</code> and{' '}
                            <code>role</code> are required; phone, service
                            number and rank are optional.
                        </li>
                        <li>
                            <code>role</code>:{' '}
                            {roles.map((role, index) => (
                                <span key={role.value}>
                                    {index > 0 && ', '}
                                    <code>{role.value}</code> ({role.label})
                                </span>
                            ))}
                            . Super admin accounts are created one at a time.
                        </li>
                        <li>
                            State Directors and Deputies need{' '}
                            <code>state_code</code> (for example <code>LA</code>
                            ); LGA Security Chiefs need <code>
                                state_code
                            </code>{' '}
                            and <code>lga_code</code>; national roles need
                            neither.
                        </li>
                        <li>
                            <code>zone_codes</code> (Deputy Director General
                            only, optional): zone codes separated by{' '}
                            <code>;</code>, from{' '}
                            {zones
                                .map((zone) => `${zone.code} (${zone.name})`)
                                .join(', ')}
                            . Empty means national.
                        </li>
                        <li>
                            Rows whose email already has an account are skipped.
                            At most {maxRows} rows per file.
                        </li>
                        <li>
                            The file is scanned for malware and is not stored.
                        </li>
                    </ul>
                    <Button variant="outline" size="sm" asChild>
                        <a href={UserImportController.template().url}>
                            <Download /> Download template
                        </a>
                    </Button>
                </div>

                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Preview
                </Button>
            </form>
        </>
    );
}

UserImport.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Import users', href: UserImportController.create() },
    ],
};
