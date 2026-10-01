import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import DynamicFields from '@/components/dynamic-fields/dynamic-fields';
import EvidenceSection from '@/components/evidence/evidence-section';
import CasualtiesEditor from '@/components/incidents/casualties-editor';
import type { CasualtyInput } from '@/components/incidents/casualties-editor';
import { Field } from '@/components/incidents/editor-parts';
import PersonsEditor from '@/components/incidents/persons-editor';
import type { PersonInput } from '@/components/incidents/persons-editor';
import {
    AgenciesEditor,
    GroupsEditor,
    PropertiesEditor,
} from '@/components/incidents/related-editors';
import type {
    AgencyInput,
    GroupInput,
    PropertyInput,
} from '@/components/incidents/related-editors';
import InputError from '@/components/input-error';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { MapView } from '@/types/dashboard';
import type { DetailValue, DetailValues } from '@/types/dynamic-fields';
import type {
    IncidentOptions,
    IncidentRecord,
    IncidentTypeOption,
} from '@/types/incidents';
import type { Option } from '@/types/local-government';

type Props = Partial<IncidentRecord> & {
    incident: IncidentRecord['incident'] | null;
    lgas: Option[];
    defaultLga: string | null;
    options: IncidentOptions;
    genders: Option[];
    canCheckRecords: boolean;
    map: MapView;
};

/** Now, for a datetime-local input (the browser's local time). */
function nowLocal(): string {
    const d = new Date();

    return new Date(d.getTime() - d.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 16);
}

/**
 * Report an incident, or edit a draft: what happened, when and where,
 * people, response, property and classification. Saved as a draft at any
 * time; the server validates everything, including the type's fields.
 */
export default function IncidentForm(props: Props) {
    const {
        incident,
        lgas,
        defaultLga,
        options,
        genders,
        canCheckRecords,
        map,
    } = props;
    const editing = incident !== null;

    const form = useForm({
        incident_type: incident?.type.ulid ?? '',
        title: incident?.title ?? '',
        description: incident?.description ?? '',
        severity: incident?.severity.value ?? '',
        occurred_at: incident?.occurred_at_local ?? nowLocal(),
        occurred_at_precision: incident?.occurred_at_precision.value ?? 'exact',
        lga: incident?.lga.ulid ?? defaultLga ?? '',
        latitude: incident?.latitude?.toString() ?? '',
        longitude: incident?.longitude?.toString() ?? '',
        location_description: incident?.location_description ?? '',
        action_taken: incident?.action_taken ?? '',
        classification: incident?.classification.value ?? 'restricted',
    });
    const [details, setDetails] = useState<DetailValues>(
        incident?.details ?? {},
    );
    const [pendingType, setPendingType] = useState<string | null>(null);
    const [community, setCommunity] = useState<PickedEntity | null>(
        incident?.community
            ? { value: incident.community.ulid, label: incident.community.name }
            : null,
    );
    const [casualties, setCasualties] = useState<CasualtyInput[]>(
        (props.casualties ?? []).map((c) => ({
            key: c.ulid,
            ulid: c.ulid,
            outcome: c.outcome.value,
            group: c.group.value,
            agency: c.agency?.ulid ?? '',
            adult_male: String(c.adult_male),
            adult_female: String(c.adult_female),
            child_male: String(c.child_male),
            child_female: String(c.child_female),
            unknown: String(c.unknown),
        })),
    );
    const [persons, setPersons] = useState<PersonInput[]>(
        (props.persons ?? []).map((p) => ({
            key: p.ulid,
            ulid: p.ulid,
            person: p.person
                ? { value: p.person.ulid, label: p.person.name }
                : null,
            keep_hidden: p.person_hidden,
            unidentified: !p.person && !p.person_hidden,
            unidentified_description: p.unidentified_description ?? '',
            involvement: p.involvement.value,
            notes: p.notes ?? '',
        })),
    );
    const [groups, setGroups] = useState<GroupInput[]>(
        (props.groups ?? []).map((g) => ({
            key: g.ulid,
            ulid: g.ulid,
            group: g.group
                ? { value: g.group.ulid, label: g.group.name }
                : null,
            keep_hidden: g.group_hidden,
            role: g.role.value,
            notes: g.notes ?? '',
        })),
    );
    const [agencies, setAgencies] = useState<AgencyInput[]>(
        (props.agencies ?? []).map((a) => ({
            key: a.ulid,
            ulid: a.ulid,
            agency: a.agency.ulid,
            formation: a.formation
                ? { value: a.formation.ulid, label: a.formation.name }
                : null,
            keep_formation: a.formation_hidden,
            role: a.role.value,
            responded_at: a.responded_at_local ?? '',
            notes: a.notes ?? '',
        })),
    );
    const [properties, setProperties] = useState<PropertyInput[]>(
        (props.properties ?? []).map((p) => ({
            key: p.ulid,
            ulid: p.ulid,
            property_type: p.property_type.ulid,
            description: p.description ?? '',
            quantity: p.quantity?.toString() ?? '',
            estimated_value_naira: p.estimated_value_naira ?? '',
            site: p.site ? { value: p.site.ulid, label: p.site.name } : null,
            keep_site: p.site_hidden,
        })),
    );

    const type =
        options.types.find((t) => t.ulid === form.data.incident_type) ?? null;
    const types = options.types;
    const grouped = useMemo(() => {
        const byCategory = new Map<
            string,
            { label: string; types: IncidentTypeOption[] }
        >();

        for (const t of types) {
            const group = byCategory.get(t.category.value) ?? {
                label: t.category.label,
                types: [],
            };
            group.types.push(t);
            byCategory.set(t.category.value, group);
        }

        return [...byCategory.values()];
    }, [types]);

    const hasDetails = Object.values(details).some(
        (v) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0),
    );

    const applyType = (ulid: string) => {
        const next = options.types.find((t) => t.ulid === ulid);
        setDetails({});
        form.setData((data) => ({
            ...data,
            incident_type: ulid,
            // Pre-fill the severity from the type (still editable).
            severity: next?.default_severity ?? data.severity,
        }));
        setPendingType(null);
    };

    const chooseType = (ulid: string) => {
        if (hasDetails && ulid !== form.data.incident_type) {
            setPendingType(ulid);
        } else {
            applyType(ulid);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (
            coordinatesProblem({
                latitude: form.data.latitude,
                longitude: form.data.longitude,
            }) !== null
        ) {
            return;
        }

        form.transform((data) => ({
            ...data,
            details,
            community: community?.value ?? null,
            latitude: data.latitude || null,
            longitude: data.longitude || null,
            casualties: casualties.map((c) => ({
                ulid: c.ulid,
                outcome: c.outcome,
                group: c.group,
                agency:
                    c.group === 'security_personnel' ? c.agency || null : null,
                adult_male: c.adult_male,
                adult_female: c.adult_female,
                child_male: c.child_male,
                child_female: c.child_female,
                unknown: c.unknown,
            })),
            persons: persons.map((p) => ({
                ulid: p.ulid,
                // Absent: keeps a person the editor cannot see.
                ...(p.keep_hidden
                    ? {}
                    : {
                          person: p.unidentified
                              ? null
                              : (p.person?.value ?? null),
                      }),
                unidentified_description: p.unidentified
                    ? p.unidentified_description
                    : null,
                involvement: p.involvement,
                notes: p.notes || null,
            })),
            groups: groups
                .filter((g) => g.keep_hidden || g.group)
                .map((g) => ({
                    ulid: g.ulid,
                    // Absent: keeps a group the editor cannot see.
                    ...(g.keep_hidden ? {} : { group: g.group?.value }),
                    role: g.role,
                    notes: g.notes || null,
                })),
            agencies: agencies.map((a) => ({
                ulid: a.ulid,
                agency: a.agency,
                ...(a.keep_formation
                    ? {}
                    : { formation: a.formation?.value ?? null }),
                role: a.role,
                responded_at: a.responded_at || null,
                notes: a.notes || null,
            })),
            properties: properties.map((p) => ({
                ulid: p.ulid,
                property_type: p.property_type,
                description: p.description || null,
                quantity: p.quantity || null,
                estimated_value_naira: p.estimated_value_naira || null,
                ...(p.keep_site ? {} : { site: p.site?.value ?? null }),
            })),
        }));

        if (editing) {
            form.put(IncidentController.update(incident.ulid).url, {
                preserveScroll: true,
            });
        } else {
            form.post(IncidentController.store().url, { preserveScroll: true });
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const firstError = Object.values(errors)[0];

    return (
        <>
            <Head
                title={
                    editing
                        ? `Edit draft: ${incident.title}`
                        : 'Report incident'
                }
            />
            <form
                onSubmit={submit}
                className="max-w-5xl space-y-8 p-4"
                noValidate
            >
                <div>
                    <h1 className="text-xl font-semibold">
                        {editing
                            ? `Edit draft: ${incident.title}`
                            : 'Report incident'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Save as a draft at any time. Submission for verification
                        comes later.
                    </p>
                </div>
                {firstError && (
                    <p
                        role="alert"
                        className="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
                    >
                        Some fields need attention. {firstError}
                    </p>
                )}

                <section className="space-y-4" aria-labelledby="s-what">
                    <h2 id="s-what" className="text-base font-semibold">
                        1. What happened
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="incident_type"
                            label="Incident type"
                            error={errors.incident_type}
                        >
                            <NativeSelect
                                id="incident_type"
                                value={form.data.incident_type}
                                onChange={(e) => chooseType(e.target.value)}
                            >
                                <option value="">Choose a type</option>
                                {grouped.map((g) => (
                                    <optgroup key={g.label} label={g.label}>
                                        {g.types.map((t) => (
                                            <option key={t.ulid} value={t.ulid}>
                                                {t.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="severity"
                            label="Severity"
                            error={errors.severity}
                        >
                            <NativeSelect
                                id="severity"
                                value={form.data.severity}
                                onChange={(e) =>
                                    form.setData('severity', e.target.value)
                                }
                            >
                                <option value="">Choose a severity</option>
                                {options.severities.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </div>
                    {pendingType && (
                        <div
                            role="alert"
                            className="flex flex-wrap items-center gap-2 rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        >
                            Changing the type clears the type-specific fields
                            already entered.
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => applyType(pendingType)}
                            >
                                Change type
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setPendingType(null)}
                            >
                                Keep the current type
                            </Button>
                        </div>
                    )}
                    {type && (
                        <div className="rounded-lg border p-3">
                            <DynamicFields
                                fields={type.fields}
                                values={details}
                                mode={editing ? 'edit' : 'new'}
                                errors={errors}
                                onChange={(key: string, value: DetailValue) =>
                                    setDetails((d) => ({ ...d, [key]: value }))
                                }
                            />
                        </div>
                    )}
                    <Field id="title" label="Title" error={errors.title}>
                        <Input
                            id="title"
                            maxLength={200}
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="description"
                        label="Description"
                        error={errors.description}
                    >
                        <Textarea
                            id="description"
                            rows={5}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                    </Field>
                </section>

                <section className="space-y-4" aria-labelledby="s-when">
                    <h2 id="s-when" className="text-base font-semibold">
                        2. When and where
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="occurred_at"
                            label="Occurred at"
                            error={errors.occurred_at}
                        >
                            <Input
                                id="occurred_at"
                                type="datetime-local"
                                max={nowLocal()}
                                value={form.data.occurred_at}
                                onChange={(e) =>
                                    form.setData('occurred_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="occurred_at_precision"
                            label="Precision"
                            error={errors.occurred_at_precision}
                        >
                            <NativeSelect
                                id="occurred_at_precision"
                                value={form.data.occurred_at_precision}
                                onChange={(e) =>
                                    form.setData(
                                        'occurred_at_precision',
                                        e.target.value,
                                    )
                                }
                            >
                                {options.precisions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field id="lga" label="LGA" error={errors.lga}>
                            <NativeSelect
                                id="lga"
                                value={form.data.lga}
                                onChange={(e) => {
                                    form.setData('lga', e.target.value);
                                    setCommunity(null);
                                }}
                            >
                                <option value="">Choose an LGA</option>
                                {lgas.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="community"
                            label="Community (optional)"
                            error={errors.community}
                        >
                            <EntityPicker
                                id="community"
                                endpoint={PickerController.communities().url}
                                params={{ lga: form.data.lga }}
                                disabled={form.data.lga === ''}
                                placeholder={
                                    form.data.lga === ''
                                        ? 'Choose the LGA first'
                                        : 'Search communities of the LGA'
                                }
                                value={community}
                                onChange={setCommunity}
                            />
                        </Field>
                        <Field
                            id="location_description"
                            label="Location description (landmark, road, junction)"
                            error={errors.location_description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="location_description"
                                value={form.data.location_description}
                                onChange={(e) =>
                                    form.setData(
                                        'location_description',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <MapPointPicker
                        value={{
                            latitude: form.data.latitude,
                            longitude: form.data.longitude,
                        }}
                        onChange={({ latitude, longitude }) =>
                            form.setData((d) => ({ ...d, latitude, longitude }))
                        }
                        center={map.center ?? undefined}
                        zoom={map.zoom}
                        errors={{
                            latitude: errors.latitude,
                            longitude: errors.longitude,
                        }}
                    />
                </section>

                <section className="space-y-6" aria-labelledby="s-people">
                    <h2 id="s-people" className="text-base font-semibold">
                        3. People
                    </h2>
                    <CasualtiesEditor
                        rows={casualties}
                        onChange={setCasualties}
                        outcomes={options.outcomes}
                        groups={options.groups}
                        agencies={options.agencies}
                        errors={errors}
                    />
                    <PersonsEditor
                        rows={persons}
                        onChange={setPersons}
                        involvements={options.involvements}
                        genders={genders}
                        lgaUlid={form.data.lga}
                        incidentUlid={incident?.ulid ?? null}
                        canCheckRecords={canCheckRecords}
                        errors={errors}
                    />
                    <GroupsEditor
                        rows={groups}
                        onChange={setGroups}
                        roles={options.groupRoles}
                        errors={errors}
                    />
                </section>

                <section className="space-y-4" aria-labelledby="s-response">
                    <h2 id="s-response" className="text-base font-semibold">
                        4. Response
                    </h2>
                    <AgenciesEditor
                        rows={agencies}
                        onChange={setAgencies}
                        agencies={options.agencies}
                        roles={options.agencyRoles}
                        errors={errors}
                    />
                    <Field
                        id="action_taken"
                        label="Action taken at the time of reporting"
                        error={errors.action_taken}
                    >
                        <Textarea
                            id="action_taken"
                            rows={3}
                            value={form.data.action_taken}
                            onChange={(e) =>
                                form.setData('action_taken', e.target.value)
                            }
                        />
                    </Field>
                </section>

                <section className="space-y-4" aria-labelledby="s-property">
                    <h2 id="s-property" className="text-base font-semibold">
                        5. Property affected
                    </h2>
                    <PropertiesEditor
                        rows={properties}
                        onChange={setProperties}
                        types={options.propertyTypes}
                        errors={errors}
                    />
                </section>

                <section className="space-y-2" aria-labelledby="s-class">
                    <h2 id="s-class" className="text-base font-semibold">
                        6. Classification
                    </h2>
                    <div className="max-w-xs">
                        <Label htmlFor="classification" className="sr-only">
                            Classification
                        </Label>
                        <NativeSelect
                            id="classification"
                            value={form.data.classification}
                            onChange={(e) =>
                                form.setData(
                                    'classification',
                                    e.target
                                        .value as typeof form.data.classification,
                                )
                            }
                        >
                            {options.classifications.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.classification} />
                    </div>
                </section>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save draft
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? IncidentController.show(incident.ulid)
                                    : IncidentController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
            <div className="max-w-5xl p-4 pt-0">
                {editing && props.evidence ? (
                    <EvidenceSection
                        evidence={props.evidence}
                        incidentUlid={incident.ulid}
                        incidentLevel={incident.classification.value}
                    />
                ) : (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Save the draft to add evidence (photos, documents, video
                        or audio).
                    </p>
                )}
            </div>
        </>
    );
}

IncidentForm.layout = {
    breadcrumbs: [{ title: 'Incidents', href: IncidentController.index() }],
};
