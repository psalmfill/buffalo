import type { FieldDefinition, FieldType } from '@/types/dynamic-fields';

export type ReferenceTableInfo = {
    slug: string;
    label: string;
    singular: string;
};

export type ReferenceColumn = { key: string; label: string };

export type ReferenceRow = {
    ulid: string;
    name: string;
    code: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    extras: Record<string, string | null>;
    /** Referenced by records (index page only). */
    in_use?: boolean;
};

/** An extra form field of a table (category, agency type, state…). */
export type ReferenceFormField = {
    name: string;
    label: string;
    type: 'select' | 'text';
    required: boolean;
    options?: { value: string; label: string }[];
    help?: string;
    /** Shown only when another field has this value. */
    showWhen?: { field: string; equals: string };
    /** Set on create, fixed afterwards. */
    createOnly?: boolean;
};

export type IncidentTypeSchema = {
    fields: FieldDefinition[];
    version: number;
    inUse: boolean;
    fieldTypes: FieldType[];
};
