import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/http';
import type { Option } from '@/types/local-government';
import type {
    OrganizationDetail,
    OrganizationOptions,
    OrganizationRow,
} from '@/types/organizations';

type Match = OrganizationRow & { same_rc_number: boolean };

/**
 * Add or edit a directory entry. Public, non-sensitive information only.
 * Before creating, possible duplicates (similar names or the same RC
 * number) are shown; the user can open one or create anyway.
 */
export default function OrganizationForm({
    organization,
    sectors,
    options,
}: {
    organization: OrganizationDetail | null;
    sectors: Option[];
    options: OrganizationOptions;
}) {
    const editing = organization !== null;
    const form = useForm({
        name: organization?.name ?? '',
        other_names: organization?.other_names ?? [],
        type: organization?.type.value ?? 'company',
        sector: organization?.sector.ulid ?? '',
        rc_number: organization?.rc_number ?? '',
        website: organization?.website ?? '',
        description: organization?.description ?? '',
        status: organization?.status.value ?? 'active',
    });
    const [matches, setMatches] = useState<Match[] | null>(null);
    const [checking, setChecking] = useState(false);

    const save = () => {
        if (editing) {
            form.put(OrganizationController.update(organization.ulid).url);
        } else {
            form.post(OrganizationController.store().url);
        }
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (editing || matches !== null) {
            save();

            return;
        }

        setChecking(true);

        try {
            const result = await postJson<{ matches: Match[] }>(
                OrganizationController.similar().url,
                {
                    name: form.data.name,
                    other_names: form.data.other_names,
                    rc_number: form.data.rc_number,
                },
            );

            if (result.matches.length === 0) {
                save();
            } else {
                setMatches(result.matches);
            }
        } finally {
            setChecking(false);
        }
    };

    const setOtherName = (index: number, value: string) =>
        form.setData(
            'other_names',
            form.data.other_names.map((n, i) => (i === index ? value : n)),
        );

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head
                title={
                    editing ? `Edit ${organization.name}` : 'Add organization'
                }
            />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">
                    {editing ? `Edit ${organization.name}` : 'Add organization'}
                </h1>
                <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                    The directory is visible to every intelligence officer
                    nationwide. Record only public, non-sensitive information
                    here. Security details belong on the organization's sites.
                </p>

                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => {
                            form.setData('name', e.target.value);
                            setMatches(null);
                        }}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="space-y-2">
                    <Label>Other names (trading or former names)</Label>
                    {form.data.other_names.map((name, index) => (
                        <div key={index} className="flex gap-2">
                            <Input
                                aria-label={`Other name ${index + 1}`}
                                value={name}
                                onChange={(e) => {
                                    setOtherName(index, e.target.value);
                                    setMatches(null);
                                }}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove"
                                onClick={() =>
                                    form.setData(
                                        'other_names',
                                        form.data.other_names.filter(
                                            (_, i) => i !== index,
                                        ),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            form.setData('other_names', [
                                ...form.data.other_names,
                                '',
                            ])
                        }
                    >
                        <Plus /> Add a name
                    </Button>
                    <InputError message={errors.other_names} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="type">Type</Label>
                        <NativeSelect
                            id="type"
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                        >
                            {options.types.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sector">Sector</Label>
                        <NativeSelect
                            id="sector"
                            value={form.data.sector}
                            onChange={(e) =>
                                form.setData('sector', e.target.value)
                            }
                        >
                            <option value="">Choose a sector</option>
                            {sectors.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.sector} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="rc_number">
                            RC number (CAC registration, optional)
                        </Label>
                        <Input
                            id="rc_number"
                            value={form.data.rc_number}
                            onChange={(e) => {
                                form.setData('rc_number', e.target.value);
                                setMatches(null);
                            }}
                        />
                        <InputError message={errors.rc_number} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            {options.statuses.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="website">
                            Website (stored as text; never opened by BUFFALO)
                        </Label>
                        <Input
                            id="website"
                            value={form.data.website}
                            onChange={(e) =>
                                form.setData('website', e.target.value)
                            }
                        />
                        <InputError message={errors.website} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="description">Description</Label>
                        <RichTextEditor
                            id="description"
                            value={form.data.description}
                            onChange={(html) =>
                                form.setData('description', html)
                            }
                            limit={5000}
                            invalid={!!form.errors.description}
                        />
                        <p className="text-xs text-muted-foreground">
                            Public, non-sensitive information only: it is
                            visible nationwide.
                        </p>
                        <InputError message={errors.description} />
                    </div>
                </div>

                {matches !== null && (
                    <section
                        role="alert"
                        className="space-y-2 rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <strong>This may already be in the directory:</strong>
                        <ul className="space-y-1">
                            {matches.map((m) => (
                                <li key={m.ulid}>
                                    <Link
                                        href={OrganizationController.show(
                                            m.ulid,
                                        )}
                                        className="font-medium underline"
                                    >
                                        {m.name}
                                    </Link>{' '}
                                    · {m.sector.name}
                                    {m.rc_number
                                        ? ` · RC no. ${m.rc_number}`
                                        : ''}
                                    {m.same_rc_number && (
                                        <strong> (same RC number)</strong>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p>
                            Open an existing entry, or create a new one anyway.
                        </p>
                    </section>
                )}

                <div className="flex gap-2">
                    <Button
                        type="submit"
                        disabled={form.processing || checking}
                    >
                        {(form.processing || checking) && <Spinner />}
                        {editing
                            ? 'Save'
                            : matches !== null
                              ? 'Create anyway'
                              : 'Add organization'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? OrganizationController.show(
                                          organization.ulid,
                                      )
                                    : OrganizationController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

OrganizationForm.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
