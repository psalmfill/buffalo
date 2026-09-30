import { Plus, Trash2 } from 'lucide-react';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import type { Errors } from '@/components/incidents/editor-parts';
import {
    Field,
    newKey,
    SelectField,
    TextField,
} from '@/components/incidents/editor-parts';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import type { Option } from '@/types/local-government';

export type GroupInput = {
    key: string;
    ulid: string | null;
    group: PickedEntity | null;
    /** A linked group the editor cannot see: kept unless replaced. */
    keep_hidden: boolean;
    role: string;
    notes: string;
};

export type AgencyInput = {
    key: string;
    ulid: string | null;
    agency: string;
    formation: PickedEntity | null;
    /** A formation the editor cannot see: kept unless replaced. */
    keep_formation: boolean;
    role: string;
    responded_at: string;
    notes: string;
};

export type PropertyInput = {
    key: string;
    ulid: string | null;
    property_type: string;
    description: string;
    quantity: string;
    estimated_value_naira: string;
    site: PickedEntity | null;
    keep_site: boolean;
};

function Header({
    title,
    onAdd,
    addLabel,
}: {
    title: string;
    onAdd: () => void;
    addLabel: string;
}) {
    return (
        <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium">{title}</h3>
            <Button type="button" size="sm" variant="outline" onClick={onAdd}>
                <Plus /> {addLabel}
            </Button>
        </div>
    );
}

function Remove({ onClick }: { onClick: () => void }) {
    return (
        <Button type="button" size="sm" variant="ghost" onClick={onClick}>
            <Trash2 /> Remove
        </Button>
    );
}

/** Criminal groups linked to the incident (groups the user can see). */
export function GroupsEditor({
    rows,
    onChange,
    roles,
    errors,
}: {
    rows: GroupInput[];
    onChange: (rows: GroupInput[]) => void;
    roles: Option[];
    errors: Errors;
}) {
    const update = (key: string, changes: Partial<GroupInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));

    return (
        <div className="space-y-3">
            <Header
                title="Criminal groups"
                addLabel="Add group"
                onAdd={() =>
                    onChange([
                        ...rows,
                        {
                            key: newKey(),
                            ulid: null,
                            group: null,
                            keep_hidden: false,
                            role: 'suspected',
                            notes: '',
                        },
                    ])
                }
            />
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No groups linked.
                </p>
            )}
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[2fr_1fr]"
                    aria-label={`Group ${i + 1}`}
                >
                    <Field
                        id={`group-${row.key}`}
                        label="Group"
                        error={errors[`groups.${i}.group`]}
                    >
                        {row.keep_hidden ? (
                            <p className="text-sm text-muted-foreground">
                                A group you cannot view.{' '}
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() =>
                                        update(row.key, {
                                            keep_hidden: false,
                                            group: null,
                                        })
                                    }
                                >
                                    Replace
                                </button>
                            </p>
                        ) : (
                            <EntityPicker
                                id={`group-${row.key}`}
                                endpoint={PickerController.groups().url}
                                placeholder="Search criminal groups"
                                value={row.group}
                                onChange={(group) => update(row.key, { group })}
                            />
                        )}
                    </Field>
                    <SelectField
                        id={`group-role-${row.key}`}
                        label="Role"
                        value={row.role}
                        options={roles}
                        onChange={(v) => update(row.key, { role: v })}
                    />
                    <TextField
                        id={`group-notes-${row.key}`}
                        label="Notes"
                        value={row.notes}
                        onChange={(v) => update(row.key, { notes: v })}
                        className="sm:col-span-2"
                    />
                    <Remove
                        onClick={() =>
                            onChange(rows.filter((r) => r.key !== row.key))
                        }
                    />
                </fieldset>
            ))}
        </div>
    );
}

/** Responding agencies, optionally the formation (filtered by agency). */
export function AgenciesEditor({
    rows,
    onChange,
    agencies,
    roles,
    errors,
}: {
    rows: AgencyInput[];
    onChange: (rows: AgencyInput[]) => void;
    agencies: Option[];
    roles: Option[];
    errors: Errors;
}) {
    const update = (key: string, changes: Partial<AgencyInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));

    return (
        <div className="space-y-3">
            <Header
                title="Responding agencies"
                addLabel="Add agency"
                onAdd={() =>
                    onChange([
                        ...rows,
                        {
                            key: newKey(),
                            ulid: null,
                            agency: '',
                            formation: null,
                            keep_formation: false,
                            role: 'first_responder',
                            responded_at: '',
                            notes: '',
                        },
                    ])
                }
            />
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No agencies recorded.
                </p>
            )}
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                    aria-label={`Agency ${i + 1}`}
                >
                    <SelectField
                        id={`agency-${row.key}`}
                        label="Agency"
                        value={row.agency}
                        options={agencies}
                        placeholder="Choose an agency"
                        onChange={(v) =>
                            update(row.key, {
                                agency: v,
                                formation: null,
                                keep_formation: false,
                            })
                        }
                        error={errors[`agencies.${i}.agency`]}
                    />
                    <Field
                        id={`agency-formation-${row.key}`}
                        label="Formation (optional)"
                        error={errors[`agencies.${i}.formation`]}
                    >
                        {row.keep_formation ? (
                            <p className="text-sm text-muted-foreground">
                                A formation you cannot view.{' '}
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() =>
                                        update(row.key, {
                                            keep_formation: false,
                                        })
                                    }
                                >
                                    Replace
                                </button>
                            </p>
                        ) : (
                            <EntityPicker
                                id={`agency-formation-${row.key}`}
                                endpoint={PickerController.formations().url}
                                params={{ agency: row.agency }}
                                disabled={row.agency === ''}
                                placeholder={
                                    row.agency === ''
                                        ? 'Choose the agency first'
                                        : 'Search formations'
                                }
                                value={row.formation}
                                onChange={(formation) =>
                                    update(row.key, { formation })
                                }
                            />
                        )}
                    </Field>
                    <SelectField
                        id={`agency-role-${row.key}`}
                        label="Role"
                        value={row.role}
                        options={roles}
                        onChange={(v) => update(row.key, { role: v })}
                    />
                    <TextField
                        id={`agency-responded-${row.key}`}
                        label="Responded at (optional)"
                        type="datetime-local"
                        value={row.responded_at}
                        onChange={(v) => update(row.key, { responded_at: v })}
                        error={errors[`agencies.${i}.responded_at`]}
                    />
                    <TextField
                        id={`agency-notes-${row.key}`}
                        label="Notes"
                        value={row.notes}
                        onChange={(v) => update(row.key, { notes: v })}
                        className="sm:col-span-2"
                    />
                    <Remove
                        onClick={() =>
                            onChange(rows.filter((r) => r.key !== row.key))
                        }
                    />
                </fieldset>
            ))}
        </div>
    );
}

/** Property affected, optionally at an organization site. */
export function PropertiesEditor({
    rows,
    onChange,
    types,
    errors,
}: {
    rows: PropertyInput[];
    onChange: (rows: PropertyInput[]) => void;
    types: Option[];
    errors: Errors;
}) {
    const update = (key: string, changes: Partial<PropertyInput>) =>
        onChange(rows.map((r) => (r.key === key ? { ...r, ...changes } : r)));

    return (
        <div className="space-y-3">
            <Header
                title="Property affected"
                addLabel="Add property"
                onAdd={() =>
                    onChange([
                        ...rows,
                        {
                            key: newKey(),
                            ulid: null,
                            property_type: '',
                            description: '',
                            quantity: '',
                            estimated_value_naira: '',
                            site: null,
                            keep_site: false,
                        },
                    ])
                }
            />
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No property recorded.
                </p>
            )}
            {rows.map((row, i) => (
                <fieldset
                    key={row.key}
                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                    aria-label={`Property ${i + 1}`}
                >
                    <SelectField
                        id={`property-type-${row.key}`}
                        label="Type"
                        value={row.property_type}
                        options={types}
                        placeholder="Choose a type"
                        onChange={(v) => update(row.key, { property_type: v })}
                        error={errors[`properties.${i}.property_type`]}
                    />
                    <TextField
                        id={`property-desc-${row.key}`}
                        label="Description"
                        value={row.description}
                        onChange={(v) => update(row.key, { description: v })}
                    />
                    <TextField
                        id={`property-qty-${row.key}`}
                        label="Quantity"
                        type="number"
                        value={row.quantity}
                        onChange={(v) => update(row.key, { quantity: v })}
                        error={errors[`properties.${i}.quantity`]}
                    />
                    <TextField
                        id={`property-value-${row.key}`}
                        label="Estimated value (₦)"
                        type="number"
                        value={row.estimated_value_naira}
                        onChange={(v) =>
                            update(row.key, { estimated_value_naira: v })
                        }
                        error={errors[`properties.${i}.estimated_value_naira`]}
                    />
                    <Field
                        id={`property-site-${row.key}`}
                        label="Organization site (optional)"
                        error={errors[`properties.${i}.site`]}
                        className="sm:col-span-2"
                    >
                        {row.keep_site ? (
                            <p className="text-sm text-muted-foreground">
                                A site you cannot view.{' '}
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() =>
                                        update(row.key, { keep_site: false })
                                    }
                                >
                                    Replace
                                </button>
                            </p>
                        ) : (
                            <EntityPicker
                                id={`property-site-${row.key}`}
                                endpoint={PickerController.sites().url}
                                placeholder="Search organization sites"
                                value={row.site}
                                onChange={(site) => update(row.key, { site })}
                            />
                        )}
                    </Field>
                    <Remove
                        onClick={() =>
                            onChange(rows.filter((r) => r.key !== row.key))
                        }
                    />
                </fieldset>
            ))}
        </div>
    );
}
