import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ChevronRight,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    FieldDefinition,
    FieldOption,
    FieldType,
} from '@/types/dynamic-fields';
import {
    FIELD_TYPE_LABELS,
    FIELD_TYPES,
    hasOptions,
    isNumeric,
    isText,
} from '@/types/dynamic-fields';

/** What records already use: these keys, types and options are fixed. */
export type SchemaLocks = {
    keys: string[];
    options: Record<string, string[]>;
};

type Errors = Record<string, string | undefined>;

/** A snake_case key from a label, e.g. "Ransom (₦)" → "ransom". */
export function toKey(label: string): string {
    const key = label
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 64);

    return /^[a-z]/.test(key) ? key : `field_${key}`.slice(0, 64);
}

function errorsUnder(errors: Errors, path: string): string[] {
    return Object.entries(errors)
        .filter(([key]) => key === path || key.startsWith(`${path}.`))
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));
}

/**
 * Edits an incident type's detail fields. Up/down buttons reorder;
 * deprecating hides a field from new entries while old records keep it.
 * When the type is in use (`locks`), keys, types and existing options are
 * fixed. The server (SchemaValidator) is authoritative either way.
 */
export default function FieldBuilder({
    fields,
    onChange,
    errors = {},
    errorPrefix = 'schema',
    locks = null,
}: {
    fields: FieldDefinition[];
    onChange: (fields: FieldDefinition[]) => void;
    errors?: Errors;
    errorPrefix?: string;
    locks?: SchemaLocks | null;
}) {
    // Which field cards are expanded, by position.
    const [open, setOpen] = useState<Record<number, boolean>>({});

    const update = (index: number, field: FieldDefinition) =>
        onChange(fields.map((item, i) => (i === index ? field : item)));

    const move = (index: number, offset: -1 | 1) => {
        const target = index + offset;
        const next = [...fields];
        [next[index], next[target]] = [next[target], next[index]];
        setOpen((current) => ({
            ...current,
            [index]: current[target] ?? false,
            [target]: current[index] ?? false,
        }));
        onChange(next);
    };

    const remove = (index: number) => {
        onChange(fields.filter((_, i) => i !== index));
        setOpen({});
    };

    const add = () => {
        let n = fields.length + 1;
        while (fields.some((field) => field.key === `new_field_${n}`)) {
            n++;
        }
        onChange([
            ...fields,
            { key: `new_field_${n}`, label: `New field ${n}`, type: 'text' },
        ]);
        setOpen((current) => ({ ...current, [fields.length]: true }));
    };

    const rootErrors = errors[errorPrefix];

    return (
        <div className="space-y-3" data-test="field-builder">
            <InputError message={rootErrors} />

            {fields.length === 0 && (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    No detail fields yet. Incidents of this type will only have
                    the standard fields.
                </p>
            )}

            {fields.map((field, index) => {
                const path = `${errorPrefix}.${index}`;
                const fieldErrors = errorsUnder(errors, path);
                const isOpen = open[index] ?? fieldErrors.length > 0;
                const lockedKey = locks?.keys.includes(field.key) ?? false;

                return (
                    <div
                        key={index}
                        className={cn(
                            'rounded-lg border bg-card',
                            field.deprecated && 'opacity-70',
                            fieldErrors.length > 0 && 'border-destructive',
                        )}
                        data-test={`field-${field.key}`}
                    >
                        <div className="flex flex-wrap items-center gap-2 p-3">
                            <button
                                type="button"
                                className="flex min-w-0 flex-1 items-center gap-2 text-left"
                                onClick={() =>
                                    setOpen({ ...open, [index]: !isOpen })
                                }
                                aria-expanded={isOpen}
                            >
                                {isOpen ? (
                                    <ChevronDown className="size-4 shrink-0" />
                                ) : (
                                    <ChevronRight className="size-4 shrink-0" />
                                )}
                                <span className="truncate font-medium">
                                    {field.label || 'Untitled field'}
                                </span>
                                <code className="truncate text-xs text-muted-foreground">
                                    {field.key}
                                </code>
                            </button>
                            <Badge variant="secondary">
                                {FIELD_TYPE_LABELS[field.type]}
                            </Badge>
                            {field.required && (
                                <Badge variant="outline">Required</Badge>
                            )}
                            {field.deprecated && (
                                <Badge variant="outline">Deprecated</Badge>
                            )}
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Move ${field.label} up`}
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Move ${field.label} down`}
                                    disabled={index === fields.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        update(index, {
                                            ...field,
                                            deprecated: !field.deprecated,
                                            required: field.deprecated
                                                ? field.required
                                                : false,
                                        })
                                    }
                                >
                                    {field.deprecated ? 'Restore' : 'Deprecate'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${field.label}`}
                                    title={
                                        lockedKey
                                            ? 'In use: deprecate this field instead'
                                            : undefined
                                    }
                                    disabled={lockedKey}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>

                        {isOpen && (
                            <FieldEditor
                                field={field}
                                path={path}
                                errors={errors}
                                lockedKey={lockedKey}
                                lockedOptions={locks?.options[field.key] ?? []}
                                onChange={(next) => update(index, next)}
                            />
                        )}
                    </div>
                );
            })}

            <Button type="button" variant="outline" onClick={add}>
                <Plus /> Add field
            </Button>
        </div>
    );
}

function FieldEditor({
    field,
    path,
    errors,
    lockedKey,
    lockedOptions,
    onChange,
}: {
    field: FieldDefinition;
    path: string;
    errors: Errors;
    lockedKey: boolean;
    lockedOptions: string[];
    onChange: (field: FieldDefinition) => void;
}) {
    const id = (property: string) => `${path}.${property}`;

    const setType = (type: FieldType) =>
        onChange({
            key: field.key,
            label: field.label,
            type,
            required: field.required,
            help: field.help,
            deprecated: field.deprecated,
            ...(hasOptions(type)
                ? {
                      options: field.options?.length
                          ? field.options
                          : [{ value: 'option_1', label: 'Option 1' }],
                  }
                : {}),
        });

    const setLabel = (label: string) =>
        onChange({
            ...field,
            label,
            // New fields follow their label until the key is edited.
            key:
                !lockedKey && field.key === toKey(field.label)
                    ? toKey(label)
                    : field.key,
        });

    const numberOrNull = (value: string) =>
        value === '' ? null : Number(value);

    return (
        <div className="grid gap-4 border-t p-3 md:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={id('label')}>Label</Label>
                <Input
                    id={id('label')}
                    value={field.label}
                    onChange={(event) => setLabel(event.target.value)}
                />
                <InputError message={errors[`${path}.label`]} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={id('key')}>Key</Label>
                <Input
                    id={id('key')}
                    value={field.key}
                    onChange={(event) =>
                        onChange({ ...field, key: event.target.value })
                    }
                    disabled={lockedKey}
                    className="font-mono"
                />
                <p className="text-xs text-muted-foreground">
                    {lockedKey
                        ? 'Fixed: records use this field.'
                        : 'Lowercase snake_case. Fixed once records use the field.'}
                </p>
                <InputError message={errors[`${path}.key`]} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={id('type')}>Type</Label>
                <NativeSelect
                    id={id('type')}
                    value={field.type}
                    onChange={(event) =>
                        setType(event.target.value as FieldType)
                    }
                    disabled={lockedKey}
                >
                    {FIELD_TYPES.map((type) => (
                        <option key={type} value={type}>
                            {FIELD_TYPE_LABELS[type]}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors[`${path}.type`]} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={id('help')}>Help text</Label>
                <Input
                    id={id('help')}
                    value={field.help ?? ''}
                    maxLength={500}
                    onChange={(event) =>
                        onChange({
                            ...field,
                            help: event.target.value || null,
                        })
                    }
                />
                <InputError message={errors[`${path}.help`]} />
            </div>

            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={field.required ?? false}
                    disabled={field.deprecated}
                    onCheckedChange={(checked) =>
                        onChange({ ...field, required: checked === true })
                    }
                />
                Required
                <InputError message={errors[`${path}.required`]} />
            </label>

            {isNumeric(field.type) && (
                <div className="grid grid-cols-2 gap-2 md:col-span-2">
                    {(['min', 'max'] as const).map((bound) => (
                        <div key={bound} className="grid gap-2">
                            <Label htmlFor={id(bound)}>
                                {bound === 'min' ? 'Minimum' : 'Maximum'}
                            </Label>
                            <Input
                                id={id(bound)}
                                type="number"
                                step={field.type === 'integer' ? 1 : 'any'}
                                value={field[bound] ?? ''}
                                onChange={(event) =>
                                    onChange({
                                        ...field,
                                        [bound]: numberOrNull(
                                            event.target.value,
                                        ),
                                    })
                                }
                            />
                            <InputError message={errors[`${path}.${bound}`]} />
                        </div>
                    ))}
                </div>
            )}

            {isText(field.type) && (
                <div className="grid gap-2">
                    <Label htmlFor={id('max_length')}>Maximum length</Label>
                    <Input
                        id={id('max_length')}
                        type="number"
                        min={1}
                        max={field.type === 'text' ? 255 : 5000}
                        placeholder={field.type === 'text' ? '255' : '5000'}
                        value={field.max_length ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...field,
                                max_length: numberOrNull(event.target.value),
                            })
                        }
                    />
                    <InputError message={errors[`${path}.max_length`]} />
                </div>
            )}

            {hasOptions(field.type) && (
                <OptionsEditor
                    options={field.options ?? []}
                    path={`${path}.options`}
                    errors={errors}
                    locked={lockedOptions}
                    onChange={(options) => onChange({ ...field, options })}
                />
            )}
        </div>
    );
}

function OptionsEditor({
    options,
    path,
    errors,
    locked,
    onChange,
}: {
    options: FieldOption[];
    path: string;
    errors: Errors;
    locked: string[];
    onChange: (options: FieldOption[]) => void;
}) {
    const update = (index: number, option: FieldOption) =>
        onChange(options.map((item, i) => (i === index ? option : item)));

    return (
        <fieldset className="grid gap-2 md:col-span-2">
            <legend className="mb-1 text-sm font-medium">Options</legend>
            <InputError message={errors[path]} />
            {options.map((option, index) => {
                const isLocked = locked.includes(option.value);

                return (
                    <div
                        key={index}
                        className="grid grid-cols-[1fr_1fr_auto_auto] items-start gap-2"
                    >
                        <div className="grid gap-1">
                            <Input
                                aria-label={`Option ${index + 1} label`}
                                placeholder="Label"
                                value={option.label}
                                onChange={(event) =>
                                    update(index, {
                                        ...option,
                                        label: event.target.value,
                                        value:
                                            !isLocked &&
                                            option.value === toKey(option.label)
                                                ? toKey(event.target.value)
                                                : option.value,
                                    })
                                }
                            />
                            <InputError
                                message={errors[`${path}.${index}.label`]}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                aria-label={`Option ${index + 1} value`}
                                placeholder="value"
                                className="font-mono"
                                value={option.value}
                                disabled={isLocked}
                                onChange={(event) =>
                                    update(index, {
                                        ...option,
                                        value: event.target.value,
                                    })
                                }
                            />
                            <InputError
                                message={errors[`${path}.${index}.value`]}
                            />
                        </div>
                        <label className="flex h-9 items-center gap-2 text-sm">
                            <Checkbox
                                checked={option.deprecated ?? false}
                                onCheckedChange={(checked) =>
                                    update(index, {
                                        ...option,
                                        deprecated: checked === true,
                                    })
                                }
                            />
                            Deprecated
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove option ${option.label}`}
                            title={
                                isLocked
                                    ? 'In use: deprecate this option instead'
                                    : undefined
                            }
                            disabled={isLocked}
                            onClick={() =>
                                onChange(options.filter((_, i) => i !== index))
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                );
            })}
            <div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        onChange([
                            ...options,
                            {
                                value: `option_${options.length + 1}`,
                                label: `Option ${options.length + 1}`,
                            },
                        ])
                    }
                >
                    <Plus /> Add option
                </Button>
            </div>
        </fieldset>
    );
}
