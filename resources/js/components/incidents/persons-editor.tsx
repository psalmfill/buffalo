import { Plus, Search, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import IncidentPersonController from '@/actions/App/Http/Controllers/Incidents/IncidentPersonController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import HitsPanel from '@/components/access-requests/hits-panel';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, postJson } from '@/lib/http';
import type { AccessRequestOptions, Hit } from '@/types/access-requests';
import type { Option } from '@/types/local-government';
import type { PersonRow } from '@/types/persons';

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

type CheckResult = {
    matches: (PersonRow & { reasons: string[] })[];
    hits: Hit[];
    hitLimitReached: boolean;
    accessOptions: AccessRequestOptions;
};

type CheckFields = {
    first_name: string;
    last_name: string;
    other_names: string;
    date_of_birth: string;
    nin: string;
    phone: string;
};

const EMPTY_CHECK: CheckFields = {
    first_name: '',
    last_name: '',
    other_names: '',
    date_of_birth: '',
    nin: '',
    phone: '',
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

/** Checks known details of an unidentified person against the records. */
function RecordCheck({
    incidentUlid,
    onUse,
    onClose,
}: {
    incidentUlid: string | null;
    onUse: (person: PickedEntity) => void;
    onClose: () => void;
}) {
    const [fields, setFields] = useState<CheckFields>(EMPTY_CHECK);
    const [result, setResult] = useState<CheckResult | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        try {
            setResult(
                await postJson<CheckResult>(
                    IncidentPersonController.check().url,
                    nulls(fields),
                ),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-2 rounded-md border border-dashed p-3">
            <p className="text-xs text-muted-foreground">
                Anything known about the person (used for the check only, not
                stored with the incident).
            </p>
            <CheckInputs fields={fields} onChange={setFields} />
            <div className="flex gap-2">
                <Button type="button" size="sm" onClick={run} disabled={busy}>
                    Check
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onClose}
                >
                    Close
                </Button>
            </div>
            {result && (
                <CheckResults
                    result={result}
                    incidentUlid={incidentUlid}
                    onUse={onUse}
                />
            )}
        </div>
    );
}

function CheckInputs({
    fields,
    onChange,
}: {
    fields: CheckFields;
    onChange: (fields: CheckFields) => void;
}) {
    const set = (key: keyof CheckFields) => (value: string) =>
        onChange({ ...fields, [key]: value });

    return (
        <div className="grid gap-2 sm:grid-cols-3">
            <TextField
                id="check-first"
                label="First name"
                value={fields.first_name}
                onChange={set('first_name')}
            />
            <TextField
                id="check-last"
                label="Last name"
                value={fields.last_name}
                onChange={set('last_name')}
            />
            <TextField
                id="check-other"
                label="Other names"
                value={fields.other_names}
                onChange={set('other_names')}
            />
            <TextField
                id="check-dob"
                label="Date of birth"
                type="date"
                value={fields.date_of_birth}
                onChange={set('date_of_birth')}
            />
            <TextField
                id="check-nin"
                label="NIN"
                value={fields.nin}
                onChange={set('nin')}
            />
            <TextField
                id="check-phone"
                label="Phone"
                value={fields.phone}
                onChange={set('phone')}
            />
        </div>
    );
}

function CheckResults({
    result,
    incidentUlid,
    onUse,
}: {
    result: CheckResult;
    incidentUlid: string | null;
    onUse: (person: PickedEntity) => void;
}) {
    return (
        <div className="space-y-2">
            {result.matches.length > 0 && (
                <ul
                    className="divide-y rounded-md border text-sm"
                    aria-label="Possible matches in your records"
                >
                    {result.matches.map((m) => (
                        <li
                            key={m.ulid}
                            className="flex items-center justify-between gap-2 px-3 py-2"
                        >
                            <span>
                                <span className="font-medium">{m.name}</span>{' '}
                                <span className="text-xs text-muted-foreground">
                                    {m.reasons.join(', ')}
                                </span>
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onUse({ value: m.ulid, label: m.name })
                                }
                            >
                                Use this person
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <HitsPanel
                hits={result.hits}
                limitReached={result.hitLimitReached}
                options={result.accessOptions}
                incidentUlid={incidentUlid}
            />
            {!incidentUlid && result.hits.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    Save the draft first so the request can cite this incident.
                </p>
            )}
        </div>
    );
}

/** Creates a new person record from the incident form, with the match check. */
function NewPersonDialog({
    genders,
    lgaUlid,
    incidentUlid,
    canCheckRecords,
    onCreated,
    onUse,
    onClose,
}: {
    genders: Option[];
    lgaUlid: string;
    incidentUlid: string | null;
    canCheckRecords: boolean;
    onCreated: (person: PickedEntity) => void;
    onUse: (person: PickedEntity) => void;
    onClose: () => void;
}) {
    const [fields, setFields] = useState<CheckFields>(EMPTY_CHECK);
    const [gender, setGender] = useState('unknown');
    const [result, setResult] = useState<CheckResult | null>(null);
    const [justification, setJustification] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);

    const check = async () => {
        setBusy(true);
        try {
            setResult(
                await postJson<CheckResult>(
                    IncidentPersonController.check().url,
                    nulls(fields),
                ),
            );
        } finally {
            setBusy(false);
        }
    };

    const create = async () => {
        setBusy(true);
        setErrors({});
        try {
            const created = await postJson<{ person: PickedEntity }>(
                IncidentPersonController.store().url,
                {
                    ...nulls(fields),
                    gender,
                    lga: lgaUlid,
                    classification: 'restricted',
                    hit_justification: justification || null,
                },
            );
            onCreated(created.person);
        } catch (error) {
            if (error instanceof HttpError && error.body.errors) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(error.body.errors).map(([k, v]) => [
                            k,
                            v[0],
                        ]),
                    ),
                );
            } else {
                setErrors({
                    form: 'The person could not be created. Try again.',
                });
            }
        } finally {
            setBusy(false);
        }
    };

    const hits = result?.hits.length ?? 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>New person</DialogTitle>
                    <DialogDescription>
                        Creates a person record in the incident's LGA. Existing
                        records are checked first.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <CheckInputs
                        fields={fields}
                        onChange={(f) => {
                            setFields(f);
                            setResult(null);
                        }}
                    />
                    <SelectField
                        id="new-gender"
                        label="Gender"
                        value={gender}
                        options={genders}
                        onChange={setGender}
                        error={errors.gender}
                    />
                    {Object.entries(errors)
                        .filter(
                            ([key]) =>
                                key !== 'gender' && key !== 'hit_justification',
                        )
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                    {result && (
                        <CheckResults
                            result={result}
                            incidentUlid={incidentUlid}
                            onUse={onUse}
                        />
                    )}
                    {result && hits > 0 && (
                        <Field
                            id="new-justification"
                            label="Why create a new record anyway?"
                            error={errors.hit_justification}
                        >
                            <Textarea
                                id="new-justification"
                                rows={2}
                                value={justification}
                                onChange={(e) =>
                                    setJustification(e.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {result === null ? (
                        <Button
                            type="button"
                            onClick={check}
                            disabled={
                                busy ||
                                fields.first_name === '' ||
                                fields.last_name === ''
                            }
                        >
                            Check records
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={create}
                            disabled={
                                busy ||
                                (hits > 0 &&
                                    canCheckRecords &&
                                    justification.trim().length < 10)
                            }
                        >
                            {hits > 0 ? 'Create anyway' : 'Create person'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function nulls(fields: CheckFields): Record<string, string | null> {
    return Object.fromEntries(
        Object.entries(fields).map(([k, v]) => [
            k,
            v.trim() === '' ? null : v.trim(),
        ]),
    );
}
