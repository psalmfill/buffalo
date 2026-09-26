import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import ClassificationSelect from '@/components/community-information/classification-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ClassificationLevel } from '@/types/auth';
import type { Option } from '@/types/local-government';
import type { GroupDetail, GroupOptions } from '@/types/political';

/** Create a criminal group (home LGA fixed afterwards) or edit one. */
export default function CriminalGroupForm({
    group,
    lgas,
    options,
}: {
    group: GroupDetail | null;
    lgas: Option[];
    options: GroupOptions;
}) {
    const editing = group !== null;
    const form = useForm({
        lga: lgas.length === 1 ? lgas[0].value : '',
        name: group?.name ?? '',
        other_names: group?.other_names ?? [],
        type: group?.type.value ?? '',
        description: group?.description ?? '',
        estimated_size: group?.estimated_size?.toString() ?? '',
        status: group?.status.value ?? 'active',
        classification: (group?.classification.value ??
            'restricted') as ClassificationLevel,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => {
            const { lga, ...rest } = data;
            const values = {
                ...rest,
                other_names: data.other_names.filter((n) => n.trim() !== ''),
                estimated_size: data.estimated_size || null,
            };

            return editing ? values : { ...values, lga };
        });

        if (editing) {
            form.put(CriminalGroupController.update(group.ulid).url);
        } else {
            form.post(CriminalGroupController.store().url);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const title = editing ? `Edit ${group.name}` : 'Add criminal group';

    const select = (
        field: 'lga' | 'type' | 'status',
        label: string,
        items: Option[],
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                required
                onChange={(e) => form.setData(field, e.target.value)}
            >
                <option value="">Select…</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={form.errors[field]} />
        </div>
    );

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    title={title}
                    description={
                        editing
                            ? `Based in ${group.lga.name} LGA, ${group.state.name} State`
                            : 'The home LGA decides who can see the group; it cannot be moved later.'
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    {!editing && select('lga', 'Home LGA', lgas)}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            required
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    {select('type', 'Type', options.types)}
                    {select('status', 'Status', options.statuses)}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="estimated_size">Estimated size</Label>
                        <Input
                            id="estimated_size"
                            inputMode="numeric"
                            value={form.data.estimated_size}
                            onChange={(e) =>
                                form.setData('estimated_size', e.target.value)
                            }
                        />
                        <InputError message={form.errors.estimated_size} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="classification">Classification</Label>
                        <ClassificationSelect
                            value={form.data.classification}
                            onChange={(level) =>
                                form.setData('classification', level)
                            }
                        />
                        <InputError message={form.errors.classification} />
                    </div>
                </div>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Other names</legend>
                    {form.data.other_names.map((name, index) => (
                        <div key={index} className="flex gap-2">
                            <Input
                                aria-label={`Other name ${index + 1}`}
                                value={name}
                                onChange={(e) =>
                                    form.setData(
                                        'other_names',
                                        form.data.other_names.map((n, i) =>
                                            i === index ? e.target.value : n,
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove name"
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
                        <Plus /> Add name
                    </Button>
                    <InputError
                        message={
                            errors.other_names ??
                            Object.entries(errors).find(([k]) =>
                                k.startsWith('other_names.'),
                            )?.[1]
                        }
                    />
                </fieldset>

                <div className="grid gap-2">
                    <Label htmlFor="description">Description</Label>
                    <Textarea
                        id="description"
                        rows={6}
                        required
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                    <InputError message={form.errors.description} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Add group'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                editing
                                    ? CriminalGroupController.show(group.ulid)
                                    : CriminalGroupController.index()
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

CriminalGroupForm.layout = {
    breadcrumbs: [
        { title: 'Criminal groups', href: CriminalGroupController.index() },
    ],
};
