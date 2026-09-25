import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ReferenceDataController from '@/actions/App/Http/Controllers/ReferenceData/ReferenceDataController';
import DynamicFields from '@/components/dynamic-fields/dynamic-fields';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { DetailValues } from '@/types/dynamic-fields';
import { FIELD_TYPE_LABELS } from '@/types/dynamic-fields';
import type {
    IncidentTypeSchema,
    ReferenceColumn,
    ReferenceRow,
    ReferenceTableInfo,
} from '@/types/reference-data';

type Props = {
    table: ReferenceTableInfo;
    columns: ReferenceColumn[];
    value: ReferenceRow;
    schema: IncidentTypeSchema | null;
    can: { manage: boolean };
};

/** Read-only view of a reference value (and an incident type's fields). */
export default function ReferenceValueShow({
    table,
    columns,
    value,
    schema,
    can,
}: Props) {
    const [preview, setPreview] = useState<DetailValues>({});

    return (
        <>
            <Head title={`${value.name} — ${table.singular}`} />
            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={value.name} description={table.singular} />
                    <div className="flex items-center gap-2">
                        <Badge
                            variant={value.is_active ? 'default' : 'secondary'}
                        >
                            {value.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        {can.manage && (
                            <Button asChild>
                                <Link
                                    href={ReferenceDataController.edit([
                                        table.slug,
                                        value.ulid,
                                    ])}
                                >
                                    Edit
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">Code</dt>
                        <dd className="font-mono text-sm">{value.code}</dd>
                    </div>
                    {columns
                        .filter((column) => column.key !== 'fields')
                        .map((column) => (
                            <div key={column.key}>
                                <dt className="text-xs text-muted-foreground">
                                    {column.label}
                                </dt>
                                <dd className="text-sm">
                                    {value.extras[column.key] ?? '—'}
                                </dd>
                            </div>
                        ))}
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-muted-foreground">
                            Description
                        </dt>
                        <dd className="text-sm">{value.description ?? '—'}</dd>
                    </div>
                </dl>

                {schema && (
                    <section className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-3">
                            <h2 className="text-sm font-semibold">
                                Detail fields (version {schema.version})
                            </h2>
                            {schema.fields.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No extra detail fields.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border text-sm">
                                    {schema.fields.map((field) => (
                                        <li
                                            key={field.key}
                                            className="flex flex-wrap items-center gap-2 px-3 py-2"
                                        >
                                            <span className="font-medium">
                                                {field.label}
                                            </span>
                                            <code className="text-xs text-muted-foreground">
                                                {field.key}
                                            </code>
                                            <Badge variant="secondary">
                                                {FIELD_TYPE_LABELS[field.type]}
                                            </Badge>
                                            {field.required && (
                                                <Badge variant="outline">
                                                    Required
                                                </Badge>
                                            )}
                                            {field.deprecated && (
                                                <Badge variant="outline">
                                                    Deprecated
                                                </Badge>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="space-y-3">
                            <h2 className="text-sm font-semibold">
                                How the form looks
                            </h2>
                            <div className="rounded-lg border p-4">
                                <DynamicFields
                                    fields={schema.fields}
                                    values={preview}
                                    onChange={(key, next) =>
                                        setPreview({ ...preview, [key]: next })
                                    }
                                    idPrefix="preview"
                                />
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

ReferenceValueShow.layout = {
    breadcrumbs: [
        { title: 'Reference data', href: ReferenceDataController.home() },
    ],
};
