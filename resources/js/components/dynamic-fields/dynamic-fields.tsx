import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    DetailValue,
    DetailValues,
    FieldDefinition,
} from '@/types/dynamic-fields';

type Props = {
    fields: FieldDefinition[];
    values: DetailValues;
    onChange: (key: string, value: DetailValue) => void;
    /** Server errors, e.g. { "details.ransom_amount": "…" }. */
    errors?: Record<string, string | undefined>;
    /** Prefix of the error keys (default "details"). */
    errorPrefix?: string;
    /**
     * "new": deprecated fields and options are hidden. "edit": a deprecated
     * field or option the record already has is still shown.
     */
    mode?: 'new' | 'edit';
    idPrefix?: string;
    disabled?: boolean;
};

/** First server error for the field, or for any of its items. */
function errorFor(
    errors: Record<string, string | undefined>,
    path: string,
): string | undefined {
    return (
        errors[path] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${path}.`))?.[1]
    );
}

function isEmpty(value: DetailValue | undefined): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/**
 * Renders an incident type's detail fields as form inputs. The server
 * (DetailsValidator) is authoritative; the inputs only guide entry.
 */
export default function DynamicFields({
    fields,
    values,
    onChange,
    errors = {},
    errorPrefix = 'details',
    mode = 'new',
    idPrefix = 'details',
    disabled = false,
}: Props) {
    const visible = fields.filter(
        (field) =>
            !field.deprecated ||
            (mode === 'edit' && !isEmpty(values[field.key])),
    );

    if (visible.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                This incident type has no extra detail fields.
            </p>
        );
    }

    return (
        <div className="grid gap-4" data-test="dynamic-fields">
            {visible.map((field) => (
                <FieldInput
                    key={field.key}
                    field={field}
                    value={values[field.key] ?? null}
                    onChange={(value) => onChange(field.key, value)}
                    error={errorFor(errors, `${errorPrefix}.${field.key}`)}
                    id={`${idPrefix}-${field.key}`}
                    disabled={disabled || field.deprecated === true}
                />
            ))}
        </div>
    );
}

function FieldInput({
    field,
    value,
    onChange,
    error,
    id,
    disabled,
}: {
    field: FieldDefinition;
    value: DetailValue;
    onChange: (value: DetailValue) => void;
    error?: string;
    id: string;
    disabled: boolean;
}) {
    const label = (
        <Label htmlFor={id}>
            {field.label}
            {field.required && (
                <span className="text-destructive" aria-hidden>
                    {' '}
                    *
                </span>
            )}
            {field.deprecated && (
                <span className="font-normal text-muted-foreground">
                    {' '}
                    (no longer used)
                </span>
            )}
        </Label>
    );

    // Options: deprecated ones only when already chosen.
    const chosen = Array.isArray(value) ? value : [String(value ?? '')];
    const options = (field.options ?? []).filter(
        (option) => !option.deprecated || chosen.includes(option.value),
    );

    const text = (next: string) => onChange(next === '' ? null : next);
    const number = (next: string) =>
        onChange(next === '' ? null : Number(next));

    let input;

    switch (field.type) {
        case 'text':
            input = (
                <Input
                    id={id}
                    value={String(value ?? '')}
                    maxLength={field.max_length ?? 255}
                    onChange={(event) => text(event.target.value)}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                />
            );
            break;
        case 'textarea':
            input = (
                <Textarea
                    id={id}
                    value={String(value ?? '')}
                    maxLength={field.max_length ?? 5000}
                    onChange={(event) => text(event.target.value)}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                />
            );
            break;
        case 'integer':
        case 'number':
            input = (
                <Input
                    id={id}
                    type="number"
                    inputMode={field.type === 'integer' ? 'numeric' : 'decimal'}
                    step={field.type === 'integer' ? 1 : 'any'}
                    min={field.min ?? undefined}
                    max={field.max ?? undefined}
                    value={value === null ? '' : String(value)}
                    onChange={(event) => number(event.target.value)}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                />
            );
            break;
        case 'boolean':
            // Three states: not recorded, yes, no.
            input = (
                <NativeSelect
                    id={id}
                    value={value === null ? '' : value ? 'yes' : 'no'}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : event.target.value === 'yes',
                        )
                    }
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                >
                    <option value="">Not recorded</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </NativeSelect>
            );
            break;
        case 'date':
        case 'datetime':
            input = (
                <Input
                    id={id}
                    type={field.type === 'date' ? 'date' : 'datetime-local'}
                    value={String(value ?? '')}
                    onChange={(event) => text(event.target.value)}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                />
            );
            break;
        case 'select':
            input = (
                <NativeSelect
                    id={id}
                    value={String(value ?? '')}
                    onChange={(event) => text(event.target.value)}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                >
                    <option value="">Select…</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </NativeSelect>
            );
            break;
        case 'multiselect': {
            const selected = Array.isArray(value) ? value : [];
            input = (
                <div
                    id={id}
                    role="group"
                    aria-label={field.label}
                    className="grid gap-2 sm:grid-cols-2"
                >
                    {options.map((option) => (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={selected.includes(option.value)}
                                onCheckedChange={(checked) => {
                                    const next =
                                        checked === true
                                            ? [...selected, option.value]
                                            : selected.filter(
                                                  (item) =>
                                                      item !== option.value,
                                              );
                                    onChange(next.length === 0 ? null : next);
                                }}
                                disabled={disabled}
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
            );
            break;
        }
    }

    return (
        <div className="grid gap-2" data-field-type={field.type}>
            {label}
            {input}
            {field.help && (
                <p className="text-xs text-muted-foreground">{field.help}</p>
            )}
            <InputError message={error} />
        </div>
    );
}
