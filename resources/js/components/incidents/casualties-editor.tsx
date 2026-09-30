import { Plus, Trash2 } from 'lucide-react';
import type { Errors } from '@/components/incidents/editor-parts';
import {
    newKey,
    SelectField,
    TextField,
} from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { Option } from '@/types/local-government';

export type CasualtyInput = {
    key: string;
    ulid: string | null;
    outcome: string;
    group: string;
    agency: string;
    adult_male: string;
    adult_female: string;
    child_male: string;
    child_female: string;
    unknown: string;
};

const COUNTS = [
    ['adult_male', 'Men'],
    ['adult_female', 'Women'],
    ['child_male', 'Boys'],
    ['child_female', 'Girls'],
    ['unknown', 'Unknown'],
] as const;

export function emptyCasualty(): CasualtyInput {
    return {
        key: newKey(),
        ulid: null,
        outcome: 'killed',
        group: 'civilian',
        agency: '',
        adult_male: '0',
        adult_female: '0',
        child_male: '0',
        child_female: '0',
        unknown: '0',
    };
}

/** Casualty rows: outcome × group, with the five counts. */
export default function CasualtiesEditor({
    rows,
    onChange,
    outcomes,
    groups,
    agencies,
    errors,
}: {
    rows: CasualtyInput[];
    onChange: (rows: CasualtyInput[]) => void;
    outcomes: Option[];
    groups: Option[];
    agencies: Option[];
    errors: Errors;
}) {
    const update = (key: string, changes: Partial<CasualtyInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-medium">Casualties</h3>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...rows, emptyCasualty()])}
                >
                    <Plus /> Add casualty row
                </Button>
            </div>
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No casualties recorded.
                </p>
            )}
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="space-y-2 rounded-lg border p-3"
                    aria-label={`Casualty row ${i + 1}`}
                >
                    <div className="grid gap-2 sm:grid-cols-3">
                        <SelectField
                            id={`cas-outcome-${row.key}`}
                            label="Outcome"
                            value={row.outcome}
                            options={outcomes}
                            onChange={(v) => update(row.key, { outcome: v })}
                            error={errors[`casualties.${i}.outcome`]}
                        />
                        <SelectField
                            id={`cas-group-${row.key}`}
                            label="Who"
                            value={row.group}
                            options={groups}
                            onChange={(v) =>
                                update(row.key, {
                                    group: v,
                                    agency:
                                        v === 'security_personnel'
                                            ? row.agency
                                            : '',
                                })
                            }
                            error={errors[`casualties.${i}.group`]}
                        />
                        {row.group === 'security_personnel' && (
                            <SelectField
                                id={`cas-agency-${row.key}`}
                                label="Agency"
                                value={row.agency}
                                options={agencies}
                                placeholder="Choose an agency"
                                onChange={(v) => update(row.key, { agency: v })}
                                error={errors[`casualties.${i}.agency`]}
                            />
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                        {COUNTS.map(([field, label]) => (
                            <TextField
                                key={field}
                                id={`cas-${field}-${row.key}`}
                                label={label}
                                type="number"
                                value={row[field]}
                                onChange={(v) =>
                                    update(row.key, { [field]: v })
                                }
                            />
                        ))}
                    </div>
                    <InputError
                        message={errors[`casualties.${i}.adult_male`]}
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            onChange(rows.filter((r) => r.key !== row.key))
                        }
                    >
                        <Trash2 /> Remove
                    </Button>
                </fieldset>
            ))}
        </div>
    );
}
