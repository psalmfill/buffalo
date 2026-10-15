import { Head, Link, router, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import { formatRadius } from '@/components/flashpoints/flashpoint-areas';
import InputError from '@/components/input-error';
import type { MapCircle } from '@/components/map/BuffaloMap';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { URGENCY_COLOURS } from '@/components/threats/threat-display';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { MapView } from '@/types/dashboard';
import type { NamedRef, Option } from '@/types/local-government';
import type {
    ThreatDetail,
    ThreatOptions,
    ThreatUrgency,
} from '@/types/threats';

type Props = {
    threat: ThreatDetail | null;
    lga: NamedRef | null;
    lgas: Option[];
    communities: Option[];
    options: ThreatOptions;
    map: MapView;
    can: { linkFlashpoints: boolean; linkGroups: boolean };
};

type GroupLink = { group: PickedEntity; role: string };

/**
 * Record or edit a threat report. Credibility and urgency are explained
 * beside the form; changing either later needs a comment. The expiry is
 * set once here (later through Extend). Flashpoints and criminal groups
 * can be linked as the report is recorded.
 */
export default function ThreatForm({
    threat,
    lga,
    lgas,
    communities,
    options,
    map,
    can,
}: Props) {
    const editing = threat !== null;
    const form = useForm({
        lga: lga?.ulid ?? '',
        threat_type: threat?.threat_type.ulid ?? '',
        title: threat?.title ?? '',
        description: threat?.description ?? '',
        credibility: threat?.credibility.value ?? 'unconfirmed',
        urgency: (threat?.urgency.value ?? 'routine') as ThreatUrgency,
        source_type: threat?.source_type.value ?? '',
        source_notes: threat?.source_notes ?? '',
        community: threat?.community?.ulid ?? '',
        latitude: threat?.latitude?.toString() ?? '',
        longitude: threat?.longitude?.toString() ?? '',
        radius_meters: threat?.radius_meters ?? 2000,
        target_description: threat?.target_description ?? '',
        expected_from: threat?.expected_from_local ?? '',
        expected_until: threat?.expected_until_local ?? '',
        expires_at: '',
        recommended_action: threat?.recommended_action ?? '',
        classification: threat?.classification.value ?? 'restricted',
        change_comment: '',
    });
    const [hasArea, setHasArea] = useState(threat?.latitude != null);
    const [flashpoints, setFlashpoints] = useState<PickedEntity[]>([]);
    const [groups, setGroups] = useState<GroupLink[]>([]);
    // Errors for the link lists, which are not form fields of their own.
    const linkErrors = form.errors as Partial<Record<string, string>>;

    const assessmentChanged =
        editing &&
        (form.data.credibility !== threat.credibility.value ||
            form.data.urgency !== threat.urgency.value);

    const point: [number, number] | null =
        hasArea &&
        coordinatesProblem({
            latitude: form.data.latitude,
            longitude: form.data.longitude,
        }) === null &&
        form.data.latitude !== '' &&
        form.data.longitude !== ''
            ? [Number(form.data.latitude), Number(form.data.longitude)]
            : null;
    const radius = form.data.radius_meters;
    const circles = useMemo<MapCircle[]>(
        () =>
            point === null
                ? []
                : [
                      {
                          id: 'area',
                          center: point,
                          radius,
                          color: URGENCY_COLOURS[form.data.urgency],
                          title: `Area: ${formatRadius(radius)} radius`,
                      },
                  ],
        // point is rebuilt each render; depend on its parts.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [point?.[0], point?.[1], radius, form.data.urgency],
    );

    const chooseLga = (value: string) => {
        form.setData((data) => ({ ...data, lga: value, community: '' }));
        router.get(
            editing
                ? ThreatReportController.edit(threat.ulid, {
                      query: { lga: value },
                  }).url
                : ThreatReportController.create({ query: { lga: value } }).url,
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
                latitude: hasArea ? data.latitude || null : null,
                longitude: hasArea ? data.longitude || null : null,
                radius_meters: hasArea ? data.radius_meters : null,
                expected_from: data.expected_from || null,
                expected_until: data.expected_until || null,
                expires_at: data.expires_at || null,
            };

            if (editing) {
                delete values.expires_at;

                if (!assessmentChanged) {
                    delete values.change_comment;
                }
            } else {
                delete values.change_comment;
                values.flashpoints = flashpoints.map((f) => f.value);
                values.groups = groups.map((g) => ({
                    group: g.group.value,
                    role: g.role,
                }));
            }

            return values;
        });

        if (editing) {
            form.put(ThreatReportController.update(threat.ulid).url);
        } else {
            form.post(ThreatReportController.store().url);
        }
    };

    const select = (
        field:
            | 'threat_type'
            | 'credibility'
            | 'urgency'
            | 'source_type'
            | 'community'
            | 'classification',
        label: string,
        items: Option[],
        placeholder: string | null,
        disabled = false,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                disabled={disabled}
                onChange={(e) => form.setData(field, e.target.value as never)}
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
            <InputError message={form.errors[field]} />
        </div>
    );

    const text = (
        field: 'title' | 'expected_from' | 'expected_until' | 'expires_at',
        label: string,
        type = 'text',
        hint?: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input
                id={field}
                type={type}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={form.errors[field]} />
        </div>
    );

    const RICH_LIMITS: Record<string, number> = {
        description: 20000,
        target_description: 2000,
        recommended_action: 5000,
    };
    const textarea = (
        field:
            | 'description'
            | 'source_notes'
            | 'target_description'
            | 'recommended_action'
            | 'change_comment',
        label: string,
        hint?: string,
        rows = 3,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {field in RICH_LIMITS ? (
                // Rich text (Phase 11B); source notes and comments stay plain text.
                <RichTextEditor
                    id={field}
                    value={form.data[field]}
                    onChange={(html) => form.setData(field, html)}
                    limit={RICH_LIMITS[field]}
                    invalid={!!form.errors[field]}
                />
            ) : (
                <Textarea
                    id={field}
                    rows={rows}
                    value={form.data[field]}
                    onChange={(e) => form.setData(field, e.target.value)}
                />
            )}
            <InputError message={form.errors[field]} />
        </div>
    );

    const title = editing
        ? `Edit ${threat.reference_no}`
        : 'Record threat report';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-6xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">{title}</h1>

                <div className="grid gap-6 lg:grid-cols-2">
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
                            <InputError message={form.errors.lga} />
                        </div>
                        {select(
                            'threat_type',
                            'Threat type',
                            options.threatTypes,
                            'Choose a threat type',
                        )}
                        {text('title', 'Title')}
                        {textarea(
                            'description',
                            'Description',
                            'What may happen, who is said to be involved, and what the report is based on.',
                            5,
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select(
                                'credibility',
                                'Credibility',
                                options.credibilities,
                                null,
                            )}
                            {select(
                                'urgency',
                                'Urgency',
                                options.urgencies,
                                null,
                            )}
                        </div>
                        {assessmentChanged &&
                            textarea(
                                'change_comment',
                                'Why did the credibility or urgency change?',
                            )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select(
                                'source_type',
                                'Source',
                                options.sourceTypes,
                                'Choose a source',
                            )}
                            {select(
                                'classification',
                                'Classification',
                                options.classifications,
                                null,
                            )}
                        </div>
                        {textarea(
                            'source_notes',
                            'Source notes (optional)',
                            "Never record an informant's identity here: name, phone number, address or anything that could identify them.",
                        )}
                        {textarea(
                            'target_description',
                            'Target (optional)',
                            'e.g. "Sunday service at a church", "weekly cattle market".',
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {text(
                                'expected_from',
                                'Expected from (optional)',
                                'datetime-local',
                            )}
                            {text(
                                'expected_until',
                                'Expected until (optional)',
                                'datetime-local',
                            )}
                        </div>
                        {editing ? (
                            <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                                Extend or reactivate the report from its page to
                                change the expiry.
                            </p>
                        ) : (
                            text(
                                'expires_at',
                                'Expires (optional)',
                                'datetime-local',
                                `Default: the expected end, or ${options.defaultExpiryDays} days from now. At most ${options.maxExpiryDays} days ahead.`,
                            )
                        )}
                        {textarea(
                            'recommended_action',
                            'Recommended action (optional)',
                        )}

                        {!editing &&
                            (can.linkFlashpoints || can.linkGroups) && (
                                <fieldset className="space-y-4 rounded-lg border p-4">
                                    <legend className="px-1 text-sm font-medium">
                                        Links (optional)
                                    </legend>
                                    {can.linkFlashpoints && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="flashpoints">
                                                Flashpoints and blackspots
                                            </Label>
                                            <EntityPicker
                                                id="flashpoints"
                                                multiple
                                                endpoint={
                                                    PickerController.flashpoints()
                                                        .url
                                                }
                                                placeholder="Search flashpoints"
                                                value={flashpoints}
                                                onChange={setFlashpoints}
                                            />
                                            <InputError
                                                message={
                                                    linkErrors.flashpoints ??
                                                    linkErrors['flashpoints.0']
                                                }
                                            />
                                        </div>
                                    )}
                                    {can.linkGroups && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="groups">
                                                Criminal groups
                                            </Label>
                                            <EntityPicker
                                                id="groups"
                                                endpoint={
                                                    PickerController.groups()
                                                        .url
                                                }
                                                placeholder="Search criminal groups"
                                                value={null}
                                                onChange={(picked) => {
                                                    if (
                                                        picked &&
                                                        !groups.some(
                                                            (g) =>
                                                                g.group
                                                                    .value ===
                                                                picked.value,
                                                        )
                                                    ) {
                                                        setGroups([
                                                            ...groups,
                                                            {
                                                                group: picked,
                                                                role: 'suspected',
                                                            },
                                                        ]);
                                                    }
                                                }}
                                            />
                                            {groups.map((g, i) => (
                                                <div
                                                    key={g.group.value}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <span className="flex-1">
                                                        {g.group.label}
                                                    </span>
                                                    <NativeSelect
                                                        aria-label={`Role of ${g.group.label}`}
                                                        value={g.role}
                                                        onChange={(e) =>
                                                            setGroups(
                                                                groups.map(
                                                                    (row, j) =>
                                                                        j === i
                                                                            ? {
                                                                                  ...row,
                                                                                  role: e
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : row,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {options.groupRoles.map(
                                                            (o) => (
                                                                <option
                                                                    key={
                                                                        o.value
                                                                    }
                                                                    value={
                                                                        o.value
                                                                    }
                                                                >
                                                                    {o.label}
                                                                </option>
                                                            ),
                                                        )}
                                                    </NativeSelect>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Remove ${g.group.label}`}
                                                        onClick={() =>
                                                            setGroups(
                                                                groups.filter(
                                                                    (_, j) =>
                                                                        j !== i,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <X />
                                                    </Button>
                                                </div>
                                            ))}
                                            <InputError
                                                message={
                                                    linkErrors.groups ??
                                                    linkErrors['groups.0.group']
                                                }
                                            />
                                        </div>
                                    )}
                                </fieldset>
                            )}
                    </section>

                    <section className="space-y-4">
                        <div
                            className="space-y-3 rounded-lg border p-4 text-sm"
                            aria-label="Credibility and urgency"
                        >
                            <h2 className="font-medium">
                                Credibility and urgency
                            </h2>
                            <dl className="space-y-1">
                                {options.credibilities.map((o) => (
                                    <div
                                        key={o.value}
                                        className={
                                            o.value === form.data.credibility
                                                ? 'font-medium'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <dt className="inline">{o.label}:</dt>{' '}
                                        <dd className="inline">{o.help}</dd>
                                    </div>
                                ))}
                            </dl>
                            <dl className="space-y-1">
                                {options.urgencies.map((o) => (
                                    <div
                                        key={o.value}
                                        className={
                                            o.value === form.data.urgency
                                                ? 'font-medium'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <dt className="inline">{o.label}:</dt>{' '}
                                        <dd className="inline">{o.help}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="has_area"
                                checked={hasArea}
                                onCheckedChange={(checked) =>
                                    setHasArea(checked === true)
                                }
                            />
                            <Label htmlFor="has_area">
                                Mark the area on the map
                            </Label>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Without a point, the community (if chosen) or the
                            whole LGA is the area of the threat.
                        </p>
                        {select(
                            'community',
                            'Community (optional)',
                            communities,
                            'None',
                            communities.length === 0,
                        )}
                        {hasArea && (
                            <>
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
                                    circles={circles}
                                    errors={{
                                        latitude: form.errors.latitude,
                                        longitude: form.errors.longitude,
                                    }}
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="radius_meters">
                                        Radius: {formatRadius(radius)}
                                    </Label>
                                    <div className="flex items-center gap-3">
                                        <input
                                            id="radius_meters"
                                            type="range"
                                            min={100}
                                            max={50000}
                                            step={100}
                                            value={radius}
                                            onChange={(e) =>
                                                form.setData(
                                                    'radius_meters',
                                                    Number(e.target.value),
                                                )
                                            }
                                            className="w-full accent-primary"
                                        />
                                        <Input
                                            aria-label="Radius in metres"
                                            type="number"
                                            min={100}
                                            max={50000}
                                            step={1}
                                            value={radius}
                                            onChange={(e) =>
                                                form.setData(
                                                    'radius_meters',
                                                    Number(e.target.value),
                                                )
                                            }
                                            className="w-28"
                                        />
                                    </div>
                                    <InputError
                                        message={form.errors.radius_meters}
                                    />
                                </div>
                            </>
                        )}
                    </section>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Record threat report'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? ThreatReportController.show(threat.ulid)
                                    : ThreatReportController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

ThreatForm.layout = {
    breadcrumbs: [
        { title: 'Threat reports', href: ThreatReportController.index() },
    ],
};
