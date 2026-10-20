import { Plus, Search, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import IncidentPersonController from '@/actions/App/Http/Controllers/Incidents/IncidentPersonController';
import {
    NewPersonDialog,
    RecordCheck,
} from '@/components/persons/person-entry';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import type { Errors } from '@/components/incidents/editor-parts';
import {
    Field,
    newKey,
    SelectField,
    TextField,
} from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type { Option } from '@/types/local-government';

export type PersonInput = {
    key: string;
    ulid: string | null;
    /** A person on record (null: unidentified). */
    person: PickedEntity | null;
    /** A linked person the editor cannot see: kept unless replaced. */
    keep_hidden: boolean;
    unidentified: boolean;
    unidentified_description: string;
    involvement: string;
    notes: string;
};

const ENDPOINTS = {
    check: IncidentPersonController.check().url,
    store: IncidentPersonController.store().url,
};

/**
 * People involved: persons on record (picker; includes persons held under
 * an active grant), unidentified persons (description), or a new person
 * record created here. Checks against other jurisdictions run with context
 * `incident_entry`.
 */
export default function PersonsEditor({
    rows,
    onChange,
    involvements,
    genders,
    lgaUlid,
    incidentUlid,
    canCheckRecords,
    errors,
}: {
    rows: PersonInput[];
    onChange: (rows: PersonInput[]) => void;
    involvements: Option[];
    genders: Option[];
    lgaUlid: string;
    incidentUlid: string | null;
    canCheckRecords: boolean;
    errors: Errors;
}) {
    const [newPerson, setNewPerson] = useState(false);
    const [checking, setChecking] = useState<string | null>(null);

    const update = (key: string, changes: Partial<PersonInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));
    const add = (changes: Partial<PersonInput>) =>
        onChange([
            ...rows,
            {
                key: newKey(),
                ulid: null,
                person: null,
                keep_hidden: false,
                unidentified: false,
                unidentified_description: '',
                involvement: 'victim',
                notes: '',
                ...changes,
            },
        ]);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-medium">Persons involved</h3>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => add({})}
                    >
                        <Plus /> Person on record
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => add({ unidentified: true })}
                    >
                        <Plus /> Unidentified person
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={lgaUlid === ''}
                        onClick={() => setNewPerson(true)}
                    >
                        <UserPlus /> New person
                    </Button>
                </div>
            </div>
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No persons recorded.
                </p>
            )}
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="space-y-2 rounded-lg border p-3"
                    aria-label={`Person ${i + 1}`}
                >
                    <div className="grid gap-2 sm:grid-cols-[2fr_1fr]">
                        {row.keep_hidden ? (
                            <div className="grid gap-1 text-sm">
                                <span className="font-medium">Person</span>
                                <p className="text-muted-foreground">
                                    Person on record (not visible to you).{' '}
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() =>
                                            update(row.key, {
                                                keep_hidden: false,
                                                person: null,
                                            })
                                        }
                                    >
                                        Replace
                                    </button>
                                </p>
                            </div>
                        ) : row.unidentified ? (
                            <Field
                                id={`person-desc-${row.key}`}
                                label="Description of the unidentified person"
                                error={
                                    errors[
                                        `persons.${i}.unidentified_description`
                                    ]
                                }
                            >
                                <Textarea
                                    id={`person-desc-${row.key}`}
                                    rows={2}
                                    value={row.unidentified_description}
                                    onChange={(e) =>
                                        update(row.key, {
                                            unidentified_description:
                                                e.target.value,
                                        })
                                    }
                                />
                                {canCheckRecords && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="justify-self-start"
                                        onClick={() => setChecking(row.key)}
                                    >
                                        <Search /> Check records
                                    </Button>
                                )}
                            </Field>
                        ) : (
                            <Field
                                id={`person-${row.key}`}
                                label="Person on record"
                                error={errors[`persons.${i}.person`]}
                            >
                                <EntityPicker
                                    id={`person-${row.key}`}
                                    endpoint={PickerController.persons().url}
                                    params={{ purpose: 'incident' }}
                                    minChars={2}
                                    placeholder="Search by name, NIN or phone"
                                    value={row.person}
                                    onChange={(person) =>
                                        update(row.key, { person })
                                    }
                                />
                            </Field>
                        )}
                        <SelectField
                            id={`person-involvement-${row.key}`}
                            label="Involvement"
                            value={row.involvement}
                            options={involvements}
                            onChange={(v) =>
                                update(row.key, { involvement: v })
                            }
                            error={errors[`persons.${i}.involvement`]}
                        />
                    </div>
                    {row.involvement === 'suspect' &&
                        row.person &&
                        !row.keep_hidden && (
                            <p className="text-xs text-muted-foreground">
                                A suspect on record may be a person of interest.{' '}
                                <a
                                    className="underline"
                                    href={
                                        PersonOfInterestController.create(
                                            row.person.value,
                                        ).url
                                    }
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Consider designating them
                                </a>
                                .
                            </p>
                        )}
                    <TextField
                        id={`person-notes-${row.key}`}
                        label="Notes"
                        value={row.notes}
                        onChange={(v) => update(row.key, { notes: v })}
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
                    {checking === row.key && (
                        <RecordCheck
                            endpoints={ENDPOINTS}
                            incidentUlid={incidentUlid}
                            onUse={(person) => {
                                update(row.key, {
                                    unidentified: false,
                                    person,
                                });
                                setChecking(null);
                            }}
                            onClose={() => setChecking(null)}
                        />
                    )}
                </fieldset>
            ))}
            <InputError message={errors.persons} />

            {newPerson && (
                <NewPersonDialog
                    endpoints={ENDPOINTS}
                    genders={genders}
                    lgaUlid={lgaUlid}
                    incidentUlid={incidentUlid}
                    canCheckRecords={canCheckRecords}
                    onCreated={(person) => {
                        add({ person });
                        setNewPerson(false);
                    }}
                    onUse={(person) => {
                        add({ person });
                        setNewPerson(false);
                    }}
                    onClose={() => setNewPerson(false)}
                />
            )}
        </div>
    );
}
