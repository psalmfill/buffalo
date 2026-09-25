import { Head, Link, useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import IncidentTypeSchemaController from '@/actions/App/Http/Controllers/ReferenceData/IncidentTypeSchemaController';
import ReferenceDataController from '@/actions/App/Http/Controllers/ReferenceData/ReferenceDataController';
import DynamicFields from '@/components/dynamic-fields/dynamic-fields';
import FieldBuilder from '@/components/dynamic-fields/field-builder';
import type { SchemaLocks } from '@/components/dynamic-fields/field-builder';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { DetailValues, FieldDefinition } from '@/types/dynamic-fields';
import type {
    IncidentTypeSchema,
    ReferenceFormField,
    ReferenceRow,
    ReferenceTableInfo,
} from '@/types/reference-data';

type Props = {
    table: ReferenceTableInfo;
    fields: ReferenceFormField[];
    /** Null when creating. */
    value: (ReferenceRow & { form: Record<string, string | number> }) | null;
    schema: IncidentTypeSchema | null;
};

/** Create or edit a reference value; incident types also get the field builder. */
export default function ReferenceValueForm({
    table,
    fields,
    value,
    schema,
}: Props) {
    const editing = value !== null;
    const initial: Record<string, string> = {
        name: '',
        code: '',
        description: '',
        sort_order: '0',
        ...Object.fromEntries(fields.map((field) => [field.name, ''])),
        ...Object.fromEntries(
            Object.entries(value?.form ?? {}).map(([key, item]) => [
                key,
                String(item ?? ''),
            ]),
        ),
    };
    const form = useForm<Record<string, string>>(initial);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Fields fixed after creation, or hidden by their condition, are not sent.
        form.transform((data) =>
            Object.fromEntries(
                Object.entries(data).filter(([key]) => {
                    if (editing && key === 'code') {
                        return false;
                    }

                    const field = fields.find((item) => item.name === key);

                    if (!field) {
                        return true;
                    }

                    if (editing && field.createOnly) {
                        return false;
                    }

                    return (
                        !field.showWhen ||
                        data[field.showWhen.field] === field.showWhen.equals
                    );
                }),
            ),
        );

        if (editing) {
            form.put(
                ReferenceDataController.update([table.slug, value.ulid]).url,
                { preserveScroll: true },
            );
        } else {
            form.post(ReferenceDataController.store(table.slug).url);
        }
    };

    const title = editing
        ? `Edit ${table.singular.toLowerCase()}`
        : `New ${table.singular.toLowerCase()}`;

    return (
        <>
            <Head title={editing ? `${value.name} — ${title}` : title} />
            <div className="max-w-6xl space-y-10 p-4">
                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <Heading
                        title={editing ? value.name : title}
                        description={
                            editing
                                ? `${table.singular} · code ${value.code}`
                                : 'The code identifies the value permanently and cannot be changed later.'
                        }
                    />

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input
                                id="code"
                                className="font-mono"
                                value={form.data.code}
                                onChange={(event) =>
                                    form.setData('code', event.target.value)
                                }
                                disabled={editing}
                                required={!editing}
                                placeholder="lowercase_snake_case"
                            />
                            <InputError message={form.errors.code} />
                        </div>

                        {fields.map((field) => {
                            if (
                                field.showWhen &&
                                form.data[field.showWhen.field] !==
                                    field.showWhen.equals
                            ) {
                                return null;
                            }

                            const locked = editing && field.createOnly;

                            return (
                                <div key={field.name} className="grid gap-2">
                                    <Label htmlFor={field.name}>
                                        {field.label}
                                    </Label>
                                    {field.type === 'select' ? (
                                        <NativeSelect
                                            id={field.name}
                                            value={form.data[field.name]}
                                            onChange={(event) =>
                                                form.setData(
                                                    field.name,
                                                    event.target.value,
                                                )
                                            }
                                            disabled={locked}
                                            required={field.required}
                                        >
                                            <option value="">Select…</option>
                                            {field.options?.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    ) : (
                                        <Input
                                            id={field.name}
                                            value={form.data[field.name]}
                                            onChange={(event) =>
                                                form.setData(
                                                    field.name,
                                                    event.target.value,
                                                )
                                            }
                                            required={field.required}
                                        />
                                    )}
                                    {(field.help || locked) && (
                                        <p className="text-xs text-muted-foreground">
                                            {locked
                                                ? 'Fixed once created.'
                                                : field.help}
                                        </p>
                                    )}
                                    <InputError
                                        message={form.errors[field.name]}
                                    />
                                </div>
                            );
                        })}

                        <div className="grid gap-2">
                            <Label htmlFor="sort_order">Sort order</Label>
                            <Input
                                id="sort_order"
                                type="number"
                                min={0}
                                value={form.data.sort_order}
                                onChange={(event) =>
                                    form.setData(
                                        'sort_order',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.sort_order} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={form.data.description}
                                maxLength={2000}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {editing ? 'Save' : 'Create'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={ReferenceDataController.index(table.slug)}
                            >
                                Back to {table.label.toLowerCase()}
                            </Link>
                        </Button>
                    </div>
                </form>

                {editing && schema && (
                    <SchemaEditor ulid={value.ulid} schema={schema} />
                )}
            </div>
        </>
    );
}

/** Field builder with a live preview, saved separately from the details. */
function SchemaEditor({
    ulid,
    schema,
}: {
    ulid: string;
    schema: IncidentTypeSchema;
}) {
    const form = useForm<{ schema: FieldDefinition[] }>({
        schema: schema.fields,
    });
    const [preview, setPreview] = useState<DetailValues>({});

    const locks: SchemaLocks | null = schema.inUse
        ? {
              keys: schema.fields.map((field) => field.key),
              options: Object.fromEntries(
                  schema.fields.map((field) => [
                      field.key,
                      (field.options ?? []).map((option) => option.value),
                  ]),
              ),
          }
        : null;

    const save = () =>
        form.put(IncidentTypeSchemaController.update(ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <section className="space-y-4" aria-labelledby="detail-fields">
            <Heading
                title="Detail fields"
                description={`Extra fields on incidents of this type (version ${schema.version}). Save them separately from the details above.`}
            />

            {schema.inUse ? (
                <Alert variant="destructive">
                    <TriangleAlert />
                    <AlertTitle>This incident type is in use</AlertTitle>
                    <AlertDescription>
                        Records use these fields, so changes are restricted: you
                        can add, relabel, reorder and deprecate fields and add
                        or deprecate options, but not rename keys, change types
                        or remove fields or options.
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert>
                    <TriangleAlert />
                    <AlertTitle>
                        Changes become restricted once the type is in use
                    </AlertTitle>
                    <AlertDescription>
                        After the first record uses this incident type, keys and
                        types are fixed and fields and options can only be
                        deprecated, not removed.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                <div className="space-y-4">
                    <FieldBuilder
                        fields={form.data.schema}
                        onChange={(next) => form.setData('schema', next)}
                        errors={errors}
                        locks={locks}
                    />
                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            onClick={save}
                            disabled={form.processing || !form.isDirty}
                        >
                            {form.processing && <Spinner />}
                            Save detail fields
                        </Button>
                        {form.isDirty && (
                            <span className="text-sm text-muted-foreground">
                                Unsaved changes
                            </span>
                        )}
                    </div>
                </div>

                <aside className="space-y-3" aria-label="Live preview">
                    <h3 className="text-sm font-semibold">Live preview</h3>
                    <p className="text-xs text-muted-foreground">
                        How the fields appear when reporting a new incident.
                        Deprecated fields are hidden.
                    </p>
                    <div className="rounded-lg border p-4" data-test="preview">
                        <DynamicFields
                            fields={form.data.schema}
                            values={preview}
                            onChange={(key, next) =>
                                setPreview({ ...preview, [key]: next })
                            }
                            idPrefix="preview"
                        />
                    </div>
                </aside>
            </div>
        </section>
    );
}

ReferenceValueForm.layout = {
    breadcrumbs: [
        {
            title: 'Reference data',
            href: ReferenceDataController.home(),
        },
    ],
};
