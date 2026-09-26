import { Head, Link, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import VulnerableFiguresController from '@/actions/App/Http/Controllers/CommunityInformation/VulnerableFiguresController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Figure } from '@/types/community-information';
import type { LgaFacts, Option } from '@/types/local-government';

type Props = {
    lga: LgaFacts;
    figure: Figure | null;
    communities: Option[];
    selectedCommunity: string;
    categories: Option[];
    today: string;
};

const COUNTS = [
    ['adult_male', 'Adult male'],
    ['adult_female', 'Adult female'],
    ['child_male', 'Child male'],
    ['child_female', 'Child female'],
] as const;

/**
 * Record vulnerable population figures (aggregate counts only), or correct
 * a recorded figure. New figures should be added with a new date: history
 * is kept.
 */
export default function VulnerableFiguresForm({
    lga,
    figure,
    communities,
    selectedCommunity,
    categories,
    today,
}: Props) {
    const editing = figure !== null;
    const form = useForm({
        community: figure?.community?.ulid ?? selectedCommunity,
        category: figure?.category.value ?? '',
        adult_male: String(figure?.adult_male ?? 0),
        adult_female: String(figure?.adult_female ?? 0),
        child_male: String(figure?.child_male ?? 0),
        child_female: String(figure?.child_female ?? 0),
        as_of_date: figure?.as_of_date ?? today,
        source: figure?.source ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            community: data.community === '' ? null : data.community,
        }));

        if (editing) {
            form.put(VulnerableFiguresController.update(figure.ulid).url);
        } else {
            form.post(VulnerableFiguresController.store(lga.ulid).url);
        }
    };

    const total = COUNTS.reduce(
        (sum, [field]) => sum + (Number(form.data[field]) || 0),
        0,
    );

    return (
        <>
            <Head title={editing ? 'Correct figures' : 'Add figures'} />
            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4">
                <Heading
                    title={
                        editing
                            ? 'Correct vulnerable population figures'
                            : 'Add vulnerable population figures'
                    }
                    description={`${lga.name} LGA, ${lga.state.name} State`}
                />

                <Alert>
                    <ShieldAlert />
                    <AlertTitle>Aggregate counts only</AlertTitle>
                    <AlertDescription>
                        Do not record names or any detail that could identify a
                        person. Enter numbers only; the source names an office,
                        not an individual.
                    </AlertDescription>
                </Alert>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="community">Community</Label>
                        <NativeSelect
                            id="community"
                            value={form.data.community}
                            onChange={(event) =>
                                form.setData('community', event.target.value)
                            }
                        >
                            <option value="">LGA-wide figure</option>
                            {communities.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.community} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="category">Category</Label>
                        <NativeSelect
                            id="category"
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData('category', event.target.value)
                            }
                            required
                        >
                            <option value="">Select…</option>
                            {categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.category} />
                    </div>
                </div>

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        Counts (total {total.toLocaleString('en-NG')})
                    </legend>
                    <div className="grid gap-4 sm:grid-cols-4">
                        {COUNTS.map(([field, label]) => (
                            <div
                                key={field}
                                className="grid content-start gap-2"
                            >
                                <Label htmlFor={field}>{label}</Label>
                                <Input
                                    id={field}
                                    type="number"
                                    min={0}
                                    step={1}
                                    value={form.data[field]}
                                    onChange={(event) =>
                                        form.setData(field, event.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors[field]} />
                            </div>
                        ))}
                    </div>
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="as_of_date">As of date</Label>
                        <Input
                            id="as_of_date"
                            type="date"
                            max={today}
                            value={form.data.as_of_date}
                            onChange={(event) =>
                                form.setData('as_of_date', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.as_of_date} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="source">Source</Label>
                        <Input
                            id="source"
                            maxLength={150}
                            placeholder="e.g. LGA social welfare office"
                            value={form.data.source}
                            onChange={(event) =>
                                form.setData('source', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.source} />
                    </div>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Add figures'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={LgaController.show(lga.ulid, {
                                query: { tab: 'vulnerable_population' },
                            })}
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

VulnerableFiguresForm.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
