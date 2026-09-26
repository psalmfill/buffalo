import { EyeOff } from 'lucide-react';
import InputError from '@/components/input-error';
import PersonPicker from '@/components/persons/person-picker';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { NamedRef } from '@/types/local-government';
import type { LinkedPerson } from '@/types/persons';

/**
 * A person linked from another record (a community's traditional ruler, a
 * religious institution's leader). When the current link is a person the
 * user cannot see, it is kept unless the user chooses to replace it
 * (`value === undefined` means "unchanged": the field is not sent).
 */
export default function LinkedPersonField({
    id,
    label,
    role,
    roleLabel,
    current,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    role: string;
    roleLabel: string;
    current: LinkedPerson | null;
    value: NamedRef | null | undefined;
    onChange: (value: NamedRef | null | undefined) => void;
    error?: string;
}) {
    const keepingHidden = current?.hidden === true && value === undefined;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {keepingHidden ? (
                <div className="flex items-center justify-between gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">
                    <span className="flex items-center gap-2">
                        <EyeOff className="size-4" /> A person you cannot view
                        is recorded.
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onChange(null)}
                    >
                        Replace
                    </Button>
                </div>
            ) : (
                <PersonPicker
                    id={id}
                    role={role}
                    value={value ?? null}
                    onChange={onChange}
                    placeholder={`Search ${roleLabel}s by name`}
                />
            )}
            <p className="text-xs text-muted-foreground">
                Only persons holding the {roleLabel} role, whom you can view.
            </p>
            <InputError message={error} />
        </div>
    );
}
