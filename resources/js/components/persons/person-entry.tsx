import { useState } from 'react';
import HitsPanel from '@/components/access-requests/hits-panel';
import type { Errors } from '@/components/incidents/editor-parts';
import {
    Field,
    SelectField,
    TextField,
} from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
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

/**
 * People entered from a record's form (incidents, Phase 5A; arrests, Phase
 * 11C): checking known details of a person against the records, and
 * creating a new person record, with the cross-jurisdiction match check
 * (hits, "Request access", "Create anyway" with a justification). The
 * server endpoints decide the match context.
 */

/** The form's check and new-person endpoints. */
export type PersonEntryEndpoints = { check: string; store: string };

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

/** Checks known details of an unidentified person against the records. */
export function RecordCheck({
    endpoints,
    record = 'incident',
    incidentUlid,
    onUse,
    onClose,
}: {
    endpoints: PersonEntryEndpoints;
    record?: string;
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
                await postJson<CheckResult>(endpoints.check, nulls(fields)),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-2 rounded-md border border-dashed p-3">
            <p className="text-xs text-muted-foreground">
                Anything known about the person (used for the check only, not
                stored with the {record}).
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
                    Link an incident, or save it first, so the request can cite
                    it.
                </p>
            )}
        </div>
    );
}

/** Creates a new person record from the incident form, with the match check. */
export function NewPersonDialog({
    endpoints,
    record = 'incident',
    genders,
    lgaUlid,
    incidentUlid,
    canCheckRecords,
    onCreated,
    onUse,
    onClose,
}: {
    endpoints: PersonEntryEndpoints;
    record?: string;
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
                await postJson<CheckResult>(endpoints.check, nulls(fields)),
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
                endpoints.store,
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
                        Creates a person record in the {record}'s LGA. Existing
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
