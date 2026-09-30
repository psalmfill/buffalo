import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types/local-government';

/** Server errors keyed like "casualties.0.agency". */
export type Errors = Record<string, string | undefined>;

let counter = 0;

/** A key for a new row (rows from the server use their ULID). */
export const newKey = (): string => `new-${++counter}`;

export function Field({
    id,
    label,
    error,
    children,
    className,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid content-start gap-1 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export function SelectField({
    id,
    label,
    value,
    options,
    onChange,
    error,
    placeholder,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    error?: string;
    placeholder?: string;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <NativeSelect
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                {placeholder !== undefined && (
                    <option value="">{placeholder}</option>
                )}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </NativeSelect>
        </Field>
    );
}

export function TextField({
    id,
    label,
    value,
    onChange,
    error,
    type = 'text',
    className,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    className?: string;
}) {
    return (
        <Field id={id} label={label} error={error} className={className}>
            <Input
                id={id}
                type={type}
                min={type === 'number' ? 0 : undefined}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </Field>
    );
}
