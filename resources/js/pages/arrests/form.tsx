import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ArrestController from '@/actions/App/Http/Controllers/Arrests/ArrestController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import ArrestPersonsEditor, {
    emptyPerson,
} from '@/components/arrests/arrest-persons-editor';
import type { ArrestPersonInput } from '@/components/arrests/arrest-persons-editor';
import type { Errors } from '@/components/incidents/editor-parts';
import { newKey } from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
import MapPointPicker from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/time';
import type {
    ArrestDetail,
    ArrestOptions,
    ArrestPersonRow,
    ArrestPrefill,
} from '@/types/arrests';
import type { MapView } from '@/types/dashboard';
import type { NamedRef, Option } from '@/types/local-government';

type Props = {
    arrest: ArrestDetail | null;
    persons: ArrestPersonRow[];
    prefill: ArrestPrefill | null;
    lga: NamedRef | null;
    lgas: Option[];
    communities: Option[];
    formations: (Option & { agency: string })[];
    options: ArrestOptions;
    genders: Option[];
    map: MapView;
    can: { checkRecords: boolean; pickIncident: boolean };
};

function fromRow(row: ArrestPersonRow): ArrestPersonInput {
    return emptyPerson({
        ulid: row.ulid,
        person: row.person
            ? { value: row.person.ulid, label: row.person.name }
            : null,
        keep_hidden: row.person_hidden,
        unidentified: !row.person && !row.person_hidden,
        unidentified_description: row.unidentified_description ?? '',
        current_status: row.status.label,
        notes: row.notes ?? '',
        offences: row.offences.map((o) => ({
            key: newKey(),
            offence: o.offence.value,
            details: o.details ?? '',
        })),
    });
}

/**
 * Record or edit an arrest (Phase 11C). From an incident ("Arrest made")
 * the form opens filled with what the incident knows: the link, place and
 * location, its responding agencies (first chosen) and its suspects as
 * suggestions; the incident's date is only a hint. Statuses are set here
 * on creation; later changes are made with a comment from the arrest page.
 */
export default function ArrestForm({
    arrest,
    persons,
    prefill,
    lga,
    lgas,
    communities,
    formations,
    options,
    genders,
    map,
    can,
}: Props) {
    const editing = arrest !== null;
    // Agencies the incident recorded come first.
    const agencies: Option[] = prefill
        ? [
              ...prefill.agencies,
              ...options.agencies.filter(
                  (a) => !prefill.agencies.some((p) => p.value === a.value),
              ),
          ]
        : options.agencies;
    const firstAgency = prefill?.agencies[0];

    const form = useForm({
        lga: lga?.ulid ?? prefill?.lga ?? '',
        community: arrest?.community?.ulid ?? prefill?.community ?? '',
        latitude:
            arrest?.latitude?.toString() ?? prefill?.latitude?.toString() ?? '',
        longitude:
            arrest?.longitude?.toString() ??
            prefill?.longitude?.toString() ??
            '',
        location_description:
            arrest?.location_description ?? prefill?.location_description ?? '',
        arrested_at: arrest?.arrested_at_local ?? '',
        arrested_at_precision: arrest?.arrested_at_precision.value ?? 'exact',
        agency: arrest?.agency.ulid ?? firstAgency?.value ?? '',
        formation: arrest?.formation?.ulid ?? firstAgency?.formation ?? '',
        status: 'in_custody',
        notes: arrest?.notes ?? '',
        classification: arrest?.classification.value ?? 'restricted',
    });
    const [incident, setIncident] = useState<PickedEntity | null>(
        arrest?.incident
            ? {
                  value: arrest.incident.ulid,
                  label: `${arrest.incident.reference_no ?? 'Draft'} · ${arrest.incident.title}`,
              }
            : (prefill?.incident ?? null),
    );
    const [rows, setRows] = useState<ArrestPersonInput[]>(
        editing ? persons.map(fromRow) : [],
    );
    const [hasLocation, setHasLocation] = useState(form.data.latitude !== '');
    const errors = form.errors as Errors;

    const agencyFormations = formations.filter(
        (f) => f.agency === form.data.agency,
    );

    const chooseLga = (value: string) => {
        form.setData((data) => ({ ...data, lga: value, community: '' }));
        router.get(
            editing
                ? ArrestController.edit(arrest.ulid, { query: { lga: value } })
                      .url
                : ArrestController.create({
                      query: {
                          lga: value,
                          ...(prefill
                              ? { incident: prefill.incident.value }
                              : {}),
                      },
                  }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['communities', 'lga', 'map'],
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => {
            const values: Record<string, unknown> = {
                ...data,
                community: data.community || null,
                formation: data.formation || null,
                latitude: hasLocation ? data.latitude || null : null,
                longitude: hasLocation ? data.longitude || null : null,
                incident: incident?.value ?? null,
                persons: rows.map((r) => ({
                    ulid: r.ulid,
                    ...(r.keep_hidden
                        ? {}
                        : {
                              person: r.unidentified
                                  ? null
                                  : (r.person?.value ?? null),
                          }),
                    unidentified_description: r.unidentified
                        ? r.unidentified_description
                        : null,
                    ...(r.ulid === null && r.status !== ''
                        ? { status: r.status }
                        : {}),
                    notes: r.notes || null,
                    offences: r.offences.map((o) => ({
                        offence: o.offence,
                        details: o.details || null,
                    })),
                })),
            };

            if (editing) {
                delete values.status;
            }

            return values;
        });

        if (editing) {
            form.put(ArrestController.update(arrest.ulid).url);
        } else {
            form.post(ArrestController.store().url);
        }
    };

    const select = (
        field:
            | 'agency'
            | 'formation'
            | 'community'
            | 'status'
            | 'classification'
            | 'arrested_at_precision',
        label: string,
        items: Option[],
        placeholder: string | null,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                onChange={(e) =>
                    form.setData((data) => ({
                        ...data,
                        [field]: e.target.value,
                        ...(field === 'agency' ? { formation: '' } : {}),
                    }))
                }
            >
                {placeholder !== null && (
                    <option value="">{placeholder}</option>
                )}
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={errors[field]} />
        </div>
    );

    const title = editing ? `Edit ${arrest.reference_no}` : 'New arrest';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-6xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">{title}</h1>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="incident">
                                Linked incident (optional)
                            </Label>
                            {can.pickIncident ? (
                                <EntityPicker
                                    id="incident"
                                    endpoint={PickerController.incidents().url}
                                    placeholder="Reference or title"
                                    value={incident}
                                    onChange={setIncident}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {incident?.label ?? 'None'}
                                </p>
                            )}
                            {prefill && (
                                <p className="text-xs text-muted-foreground">
                                    The incident occurred{' '}
                                    {formatDateTime(prefill.occurred_at)}. The
                                    time of arrest is not filled in.
                                </p>
                            )}
                            <InputError message={errors.incident} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="arrested_at">
                                    Time of arrest
                                </Label>
                                <Input
                                    id="arrested_at"
                                    type="datetime-local"
                                    value={form.data.arrested_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'arrested_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.arrested_at} />
                            </div>
                            {select(
                                'arrested_at_precision',
                                'How precise',
                                options.precisions,
                                null,
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {select(
                                'agency',
                                'Arresting agency',
                                agencies,
                                'Choose an agency',
                            )}
                            {select(
                                'formation',
                                'Formation (optional)',
                                agencyFormations,
                                'None',
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {!editing &&
                                select(
                                    'status',
                                    'Status',
                                    options.statuses,
                                    null,
                                )}
                            {select(
                                'classification',
                                'Classification',
                                options.classifications,
                                null,
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes (optional)</Label>
                            <RichTextEditor
                                id="notes"
                                value={form.data.notes}
                                onChange={(html) => form.setData('notes', html)}
                                limit={10000}
                                invalid={!!errors.notes}
                            />
                            <InputError message={errors.notes} />
                        </div>
                    </section>

                    <section className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="lga">LGA</Label>
                            <NativeSelect
                                id="lga"
                                value={form.data.lga}
                                onChange={(e) => chooseLga(e.target.value)}
                            >
                                <option value="">Choose an LGA</option>
                                {lgas.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.lga} />
                        </div>
                        {select(
                            'community',
                            'Community (optional)',
                            communities,
                            'None',
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="location_description">
                                Where (optional)
                            </Label>
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
                            <InputError message={errors.location_description} />
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="has-location"
                                checked={hasLocation}
                                onCheckedChange={(v) =>
                                    setHasLocation(v === true)
                                }
                            />
                            <Label htmlFor="has-location">
                                Mark the place on the map
                            </Label>
                        </div>
                        {hasLocation && (
                            <MapPointPicker
                                value={{
                                    latitude: form.data.latitude,
                                    longitude: form.data.longitude,
                                }}
                                onChange={({ latitude, longitude }) =>
                                    form.setData((data) => ({
                                        ...data,
                                        latitude,
                                        longitude,
                                    }))
                                }
                                center={map.center ?? undefined}
                                zoom={map.zoom}
                                outline={map.outline}
                                errors={{
                                    latitude: errors.latitude,
                                    longitude: errors.longitude,
                                }}
                            />
                        )}
                    </section>
                </div>

                <ArrestPersonsEditor
                    rows={rows}
                    onChange={setRows}
                    statuses={options.statuses}
                    offences={options.offences}
                    genders={genders}
                    lgaUlid={form.data.lga}
                    incidentUlid={incident?.value ?? null}
                    canCheckRecords={can.checkRecords}
                    editing={editing}
                    suspects={prefill?.suspects ?? []}
                    errors={errors}
                />

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save arrest' : 'Record arrest'}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => window.history.back()}
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </>
    );
}

ArrestForm.layout = {
    breadcrumbs: [{ title: 'Arrests', href: ArrestController.index() }],
};
