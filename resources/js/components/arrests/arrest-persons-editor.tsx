import { Link } from '@inertiajs/react';
import { Plus, Search, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import ArrestPersonController from '@/actions/App/Http/Controllers/Arrests/ArrestPersonController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import type { Errors } from '@/components/incidents/editor-parts';
import {
    Field,
    newKey,
    SelectField,
    TextField,
} from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
import {
    NewPersonDialog,
    RecordCheck,
} from '@/components/persons/person-entry';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';
import type { ArrestPrefill, ArrestSuggestion } from '@/types/arrests';
import type { Option } from '@/types/local-government';

export type OffenceInput = { key: string; offence: string; details: string };

export type ArrestPersonInput = {
    key: string;
    ulid: string | null;
    person: PickedEntity | null;
    /** A linked person the editor cannot see: kept unless replaced. */
    keep_hidden: boolean;
    unidentified: boolean;
    unidentified_description: string;
    /** Set for new rows; existing rows change status from the arrest page. */
    status: string;
    current_status: string | null;
    notes: string;
    offences: OffenceInput[];
};

const ENDPOINTS = {
    check: ArrestPersonController.check().url,
    store: ArrestPersonController.store().url,
};

export function emptyPerson(
    changes: Partial<ArrestPersonInput> = {},
): ArrestPersonInput {
    return {
        key: newKey(),
        ulid: null,
        person: null,
        keep_hidden: false,
        unidentified: false,
        unidentified_description: '',
        status: '',
        current_status: null,
        notes: '',
        offences: [{ key: newKey(), offence: '', details: '' }],
        ...changes,
    };
}

/** What to suggest about an arrested person; never applied automatically. */
function Suggestion({ suggestion }: { suggestion: ArrestSuggestion }) {
    return suggestion.kind === 'poi_detained' ? (
        <p
            className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
            data-test="suggest-detained"
        >
            This person is a person of interest ({suggestion.current}). Consider
            updating their status to <strong>detained</strong>:{' '}
            <Link
                className="underline"
                href={PersonController.show(suggestion.person, {
                    query: { tab: 'poi' },
                })}
                target="_blank"
            >
                open their POI record
            </Link>
            .
        </p>
    ) : (
        <p
            className="text-xs text-muted-foreground"
            data-test="suggest-designate"
        >
            Not a person of interest.{' '}
            <Link
                className="underline"
                href={PersonOfInterestController.create(suggestion.person)}
                target="_blank"
            >
                Designate as person of interest
            </Link>
        </p>
    );
}

/**
 * Persons arrested (Phase 11C): a person on record (picker, including
 * persons held under an active grant), an unidentified person described in
 * words (with a check against the records), or a new person record. Each
 * with at least one offence. Checks against other jurisdictions run with
 * context `arrest_entry`. Suspects from a linked incident are offered.
 */
export default function ArrestPersonsEditor({
    rows,
    onChange,
    statuses,
    offences,
    genders,
    lgaUlid,
    incidentUlid,
    canCheckRecords,
    editing,
    suspects,
    errors,
}: {
    rows: ArrestPersonInput[];
    onChange: (rows: ArrestPersonInput[]) => void;
    statuses: Option[];
    offences: Option[];
    genders: Option[];
    lgaUlid: string;
    incidentUlid: string | null;
    canCheckRecords: boolean;
    editing: boolean;
    suspects: ArrestPrefill['suspects'];
    errors: Errors;
}) {
    const [newPerson, setNewPerson] = useState(false);
    const [checking, setChecking] = useState<string | null>(null);
    const [suggestions, setSuggestions] = useState<
        Record<string, ArrestSuggestion | null>
    >({});

    const update = (key: string, changes: Partial<ArrestPersonInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));
    const add = (changes: Partial<ArrestPersonInput>) =>
        onChange([...rows, emptyPerson(changes)]);

    // Suggestions for the persons picked (POI status or designation).
    const picked = rows
        .map((r) => (r.keep_hidden ? null : r.person?.value))
        .filter((v): v is string => typeof v === 'string');
    const pickedKey = picked.join(',');
    useEffect(() => {
        const missing = picked.filter((u) => !(u in suggestions));
        if (missing.length === 0) {
            return;
        }
        postJson<{ suggestions: Record<string, ArrestSuggestion | null> }>(
            ArrestPersonController.suggestions().url,
            { persons: missing },
        )
            .then((r) =>
                setSuggestions((s) => ({
                    ...s,
                    ...Object.fromEntries(missing.map((u) => [u, null])),
                    ...r.suggestions,
                })),
            )
            .catch(() => undefined);
        // pickedKey stands for the picked persons.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pickedKey]);

    const offeredSuspects = suspects.filter(
        (s) =>
            !rows.some(
                (r) =>
                    (s.person && r.person?.value === s.person.value) ||
                    (!s.person &&
                        r.unidentified &&
                        r.unidentified_description ===
                            s.unidentified_description),
            ),
    );

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Persons arrested</h2>
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

            {offeredSuspects.length > 0 && (
                <div
                    className="space-y-2 rounded-lg border border-dashed p-3"
                    data-test="suggested-suspects"
                >
                    <p className="text-xs text-muted-foreground">
                        Suspects on the incident (add those who were arrested):
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {offeredSuspects.map((s, i) => (
                            <Button
                                key={i}
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    add(
                                        s.person
                                            ? { person: s.person }
                                            : {
                                                  unidentified: true,
                                                  unidentified_description:
                                                      s.unidentified_description ??
                                                      '',
                                              },
                                    )
                                }
                            >
                                <Plus />{' '}
                                {s.person?.label ??
                                    `Unidentified: ${s.unidentified_description}`}
                            </Button>
                        ))}
                    </div>
                </div>
            )}

            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Add at least one arrested person.
                </p>
            )}

            {rows.map((row, i) => {
                const suggestion =
                    row.person && !row.keep_hidden
                        ? suggestions[row.person.value]
                        : null;

                return (
                    <fieldset
                        key={row.key}
                        className="space-y-3 rounded-lg border p-3"
                        aria-label={`Arrested person ${i + 1}`}
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
                                    id={`arrest-person-desc-${row.key}`}
                                    label="Description of the unidentified person"
                                    error={
                                        errors[
                                            `persons.${i}.unidentified_description`
                                        ]
                                    }
                                >
                                    <Textarea
                                        id={`arrest-person-desc-${row.key}`}
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
                                    id={`arrest-person-${row.key}`}
                                    label="Person on record"
                                    error={errors[`persons.${i}.person`]}
                                >
                                    <EntityPicker
                                        id={`arrest-person-${row.key}`}
                                        endpoint={
                                            PickerController.persons().url
                                        }
                                        params={{ purpose: 'arrest' }}
                                        minChars={2}
                                        placeholder="Search by name, NIN or phone"
                                        value={row.person}
                                        onChange={(person) =>
                                            update(row.key, { person })
                                        }
                                    />
                                </Field>
                            )}
                            {row.ulid !== null && row.current_status ? (
                                <div className="grid gap-1 text-sm">
                                    <span className="font-medium">Status</span>
                                    <p className="text-muted-foreground">
                                        {row.current_status} (change it from the
                                        arrest page)
                                    </p>
                                </div>
                            ) : (
                                <SelectField
                                    id={`arrest-person-status-${row.key}`}
                                    label={
                                        editing
                                            ? 'Status'
                                            : 'Status (default: the arrest’s)'
                                    }
                                    value={row.status}
                                    options={[
                                        ...(editing
                                            ? []
                                            : [
                                                  {
                                                      value: '',
                                                      label: 'Same as the arrest',
                                                  },
                                              ]),
                                        ...statuses,
                                    ]}
                                    onChange={(v) =>
                                        update(row.key, { status: v })
                                    }
                                    error={errors[`persons.${i}.status`]}
                                />
                            )}
                        </div>

                        {suggestion && <Suggestion suggestion={suggestion} />}

                        <div className="space-y-2">
                            <p className="text-xs font-medium">Offences</p>
                            {row.offences.map((o, j) => (
                                <div
                                    key={o.key}
                                    className="grid items-end gap-2 sm:grid-cols-[1fr_2fr_auto]"
                                >
                                    <SelectField
                                        id={`offence-${o.key}`}
                                        label="Offence"
                                        value={o.offence}
                                        options={[
                                            {
                                                value: '',
                                                label: 'Choose an offence',
                                            },
                                            ...offences,
                                        ]}
                                        onChange={(v) =>
                                            update(row.key, {
                                                offences: row.offences.map(
                                                    (x) =>
                                                        x.key === o.key
                                                            ? {
                                                                  ...x,
                                                                  offence: v,
                                                              }
                                                            : x,
                                                ),
                                            })
                                        }
                                        error={
                                            errors[
                                                `persons.${i}.offences.${j}.offence`
                                            ]
                                        }
                                    />
                                    <TextField
                                        id={`offence-details-${o.key}`}
                                        label="Details (optional)"
                                        value={o.details}
                                        onChange={(v) =>
                                            update(row.key, {
                                                offences: row.offences.map(
                                                    (x) =>
                                                        x.key === o.key
                                                            ? {
                                                                  ...x,
                                                                  details: v,
                                                              }
                                                            : x,
                                                ),
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={row.offences.length === 1}
                                        aria-label="Remove offence"
                                        onClick={() =>
                                            update(row.key, {
                                                offences: row.offences.filter(
                                                    (x) => x.key !== o.key,
                                                ),
                                            })
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ))}
                            <InputError
                                message={errors[`persons.${i}.offences`]}
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    update(row.key, {
                                        offences: [
                                            ...row.offences,
                                            {
                                                key: newKey(),
                                                offence: '',
                                                details: '',
                                            },
                                        ],
                                    })
                                }
                            >
                                <Plus /> Offence
                            </Button>
                        </div>

                        <TextField
                            id={`arrest-person-notes-${row.key}`}
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
                            <Trash2 /> Remove person
                        </Button>
                        {checking === row.key && (
                            <RecordCheck
                                endpoints={ENDPOINTS}
                                record="arrest"
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
                );
            })}
            <InputError message={errors.persons} />

            {newPerson && (
                <NewPersonDialog
                    endpoints={ENDPOINTS}
                    record="arrest"
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
