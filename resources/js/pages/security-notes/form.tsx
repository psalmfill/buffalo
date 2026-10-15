import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SecurityNoteController from '@/actions/App/Http/Controllers/CommunityInformation/SecurityNoteController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import ClassificationSelect from '@/components/community-information/classification-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ClassificationLevel } from '@/types/auth';
import type { NoteDetail } from '@/types/community-information';
import type { LgaFacts, Option } from '@/types/local-government';

type Props = {
    lga: LgaFacts;
    note: NoteDetail | null;
    communities: Option[];
    selectedCommunity: string;
    categories: Option[];
    statuses: Option[];
    today: string;
};

/**
 * Record or edit a community security note. The classification offers only
 * levels the user may assign; the server checks again.
 */
export default function SecurityNoteForm({
    lga,
    note,
    communities,
    selectedCommunity,
    categories,
    statuses,
    today,
}: Props) {
    const editing = note !== null;
    const form = useForm({
        community: note?.community.ulid ?? selectedCommunity,
        category: note?.category.value ?? '',
        title: note?.title ?? '',
        description: note?.description ?? '',
        classification: (note?.classification.value ??
            'restricted') as ClassificationLevel,
        observed_at: note?.observed_at ?? today,
        status: 'active',
        resolved_at: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.transform((data) => ({
                community: data.community,
                category: data.category,
                title: data.title,
                description: data.description,
                classification: data.classification,
                observed_at: data.observed_at,
            }));
            form.put(SecurityNoteController.update(note.ulid).url);
        } else {
            form.transform((data) => ({
                ...data,
                resolved_at:
                    data.status === 'resolved' ? data.resolved_at : null,
            }));
            form.post(SecurityNoteController.store(lga.ulid).url);
        }
    };

    const select = (
        field: 'community' | 'category' | 'status',
        label: string,
        options: Option[],
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                onChange={(event) => form.setData(field, event.target.value)}
                required
            >
                {field !== 'status' && <option value="">Select…</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={form.errors[field]} />
        </div>
    );

    const title = editing ? 'Edit security note' : 'Add security note';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading
                    title={title}
                    description={`${lga.name} LGA, ${lga.state.name} State`}
                />

                <div className="grid gap-2">
                    <Label htmlFor="title">Title</Label>
                    <Input
                        id="title"
                        maxLength={200}
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                        required
                    />
                    <InputError message={form.errors.title} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {select('community', 'Community', communities)}
                    {select('category', 'Category', categories)}
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
                    <div className="grid content-start gap-2">
                        <Label htmlFor="observed_at">Observed on</Label>
                        <Input
                            id="observed_at"
                            type="date"
                            max={today}
                            value={form.data.observed_at}
                            onChange={(event) =>
                                form.setData('observed_at', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.observed_at} />
                    </div>
                    {!editing && select('status', 'Status', statuses)}
                    {!editing && form.data.status === 'resolved' && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="resolved_at">Resolved on</Label>
                            <Input
                                id="resolved_at"
                                type="date"
                                max={today}
                                value={form.data.resolved_at}
                                onChange={(event) =>
                                    form.setData(
                                        'resolved_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.resolved_at} />
                        </div>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">Description</Label>
                    <RichTextEditor
                        id="description"
                        value={form.data.description}
                        onChange={(html) => form.setData('description', html)}
                        limit={20000}
                        invalid={!!form.errors.description}
                    />
                    <InputError message={form.errors.description} />
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Add note'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                editing
                                    ? SecurityNoteController.show(note.ulid)
                                    : LgaController.show(lga.ulid, {
                                          query: { tab: 'security_notes' },
                                      })
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

SecurityNoteForm.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
