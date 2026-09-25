/**
 * An incident type's detail fields (details_schema): BUFFALO's own format,
 * validated on the server by App\Support\DynamicFields\SchemaValidator.
 */
export const FIELD_TYPES = [
    'text',
    'textarea',
    'integer',
    'number',
    'boolean',
    'date',
    'datetime',
    'select',
    'multiselect',
] as const;

export type FieldType = (typeof FIELD_TYPES)[number];

export type FieldOption = {
    value: string;
    label: string;
    deprecated?: boolean;
};

export type FieldDefinition = {
    key: string;
    label: string;
    type: FieldType;
    required?: boolean;
    options?: FieldOption[];
    min?: number | null;
    max?: number | null;
    max_length?: number | null;
    help?: string | null;
    deprecated?: boolean;
};

export type DetailValue = string | number | boolean | string[] | null;

export type DetailValues = Record<string, DetailValue>;

export const FIELD_TYPE_LABELS: Record<FieldType, string> = {
    text: 'Short text',
    textarea: 'Long text',
    integer: 'Whole number',
    number: 'Number',
    boolean: 'Yes / no',
    date: 'Date',
    datetime: 'Date and time',
    select: 'Choose one',
    multiselect: 'Choose several',
};

export function hasOptions(type: FieldType): boolean {
    return type === 'select' || type === 'multiselect';
}

export function isNumeric(type: FieldType): boolean {
    return type === 'integer' || type === 'number';
}

export function isText(type: FieldType): boolean {
    return type === 'text' || type === 'textarea';
}
