import { Head, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import CommunityImportController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    lgas: { ulid: string; name: string; state: string }[];
    selected: string;
    columns: string[];
    threshold: number;
};

/** Upload a community CSV for an LGA; nothing is imported until confirmed. */
export default function CommunityImport({
    lgas,
    selected,
    columns,
    threshold,
}: Props) {
    const form = useForm<{ lga: string; file: File | null }>({
        lga: lgas.some((lga) => lga.ulid === selected) ? selected : '',
        file: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(CommunityImportController.preview().url, {
            forceFormData: true,
        });
    };

    return (
        <>
            <Head title="Import communities" />
            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Import communities"
                    description="Upload a CSV to add many communities to one LGA. You will see a preview of every row before anything is imported."
                />

                <div className="grid gap-2">
                    <Label htmlFor="lga">LGA</Label>
                    <NativeSelect
                        id="lga"
                        value={form.data.lga}
                        onChange={(event) =>
                            form.setData('lga', event.target.value)
                        }
                        required
                    >
                        <option value="">Choose an LGA…</option>
                        {lgas.map((lga) => (
                            <option key={lga.ulid} value={lga.ulid}>
                                {lga.name} ({lga.state})
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.lga} />
                </div>

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
                    <ul className="list-inside list-disc text-muted-foreground">
                        <li>
                            <code>type</code>: village, town, settlement, camp,
                            estate, waterside or other.
                        </li>
                        <li>
                            <code>ward_code</code>, coordinates, population,
                            other names and notes are optional.
                        </li>
                        <li>
                            Separate several other names with <code>|</code>.
                        </li>
                        <li>
                            Files with more than {threshold} rows are imported
                            in the background; you are notified when done.
                        </li>
                        <li>The file itself is not stored.</li>
                    </ul>
                    <Button variant="outline" size="sm" asChild>
                        <a href={CommunityImportController.template().url}>
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

CommunityImport.layout = {
    breadcrumbs: [
        {
            title: 'Import communities',
            href: CommunityImportController.create(),
        },
    ],
};
