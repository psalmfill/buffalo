import { formatDateTime } from '@/lib/time';
import type {
    DetailValue,
    DetailValues,
    FieldDefinition,
} from '@/types/dynamic-fields';

function display(field: FieldDefinition, value: DetailValue): string {
    const optionLabel = (raw: string) =>
        field.options?.find((option) => option.value === raw)?.label ?? raw;

    switch (field.type) {
        case 'boolean':
            return value ? 'Yes' : 'No';
        case 'select':
            return optionLabel(String(value));
        case 'multiselect':
            return (Array.isArray(value) ? value : [String(value)])
                .map(optionLabel)
                .join(', ');
        case 'integer':
        case 'number':
            return typeof value === 'number'
                ? value.toLocaleString('en-NG')
                : String(value);
        case 'datetime':
            return formatDateTime(String(value));
        default:
            return String(value);
    }
}

/**
 * Read-only display of saved detail values, with option labels rather than
 * raw values. Deprecated fields are shown too: old records keep them.
 */
export default function DynamicFieldsView({
    fields,
    values,
}: {
    fields: FieldDefinition[];
    values: DetailValues;
}) {
    const shown = fields.filter((field) => {
        const value = values[field.key];

        return (
            value !== null &&
            value !== undefined &&
            value !== '' &&
            !(Array.isArray(value) && value.length === 0)
        );
    });

    if (shown.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No details recorded.
            </p>
        );
    }

    return (
        <dl
            className="grid gap-3 sm:grid-cols-2"
            data-test="dynamic-fields-view"
        >
            {shown.map((field) => (
                <div key={field.key}>
                    <dt className="text-xs text-muted-foreground">
                        {field.label}
                        {field.deprecated && ' (no longer used)'}
                    </dt>
                    <dd className="text-sm">
                        {display(field, values[field.key] ?? null)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
