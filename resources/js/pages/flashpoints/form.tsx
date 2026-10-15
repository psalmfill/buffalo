import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import {
    CONCERN_COLOURS,
    formatRadius,
} from '@/components/flashpoints/flashpoint-areas';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import InputError from '@/components/input-error';
import type { MapCircle } from '@/components/map/BuffaloMap';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { MapView } from '@/types/dashboard';
import type {
    ConcernLevel,
    FlashpointDetail,
    FlashpointOptions,
} from '@/types/flashpoints';
import type { NamedRef, Option } from '@/types/local-government';

type Props = {
    flashpoint: FlashpointDetail | null;
    lga: NamedRef | null;
    lgas: Option[];
    communities: Option[];
    options: FlashpointOptions;
    map: MapView;
    /** Phase 6C: pre-filled from a suggested blackspot being accepted. */
    suggestion: {
        ulid: string;
        latitude: number | null;
        longitude: number | null;
        radius_meters: number;
        threat_type: string | null;
        dominant_type: string | null;
    } | null;
};

const MIN_RADIUS = 100;
const MAX_RADIUS = 50000;

/**
 * Record a flashpoint or blackspot with its first assessment, or edit one.
 * The point is picked on the map; the radius slider redraws the area live.
 * Once recorded, concern level, status and recommended action change only
 * through an assessment.
 */
export default function FlashpointForm({
    flashpoint,
    lga,
    lgas,
    communities,
    options,
    map,
    suggestion,
}: Props) {
    const editing = flashpoint !== null;
    const form = useForm({
        lga: lga?.ulid ?? '',
        kind:
            flashpoint?.kind.value ?? (suggestion ? 'blackspot' : 'flashpoint'),
        name: flashpoint?.name ?? '',
        threat_type:
            flashpoint?.threat_type.ulid ?? suggestion?.threat_type ?? '',
        community: flashpoint?.community?.ulid ?? '',
        latitude:
            flashpoint?.latitude.toString() ??
            suggestion?.latitude?.toString() ??
            '',
        longitude:
            flashpoint?.longitude.toString() ??
            suggestion?.longitude?.toString() ??
            '',
        radius_meters:
            flashpoint?.radius_meters ?? suggestion?.radius_meters ?? 1000,
        description: flashpoint?.description ?? '',
        classification: flashpoint?.classification.value ?? 'restricted',
        concern_level: 'moderate' as ConcernLevel,
        status: 'active',
        assessment: '',
        recommended_action: '',
    });

    const point: [number, number] | null =
        coordinatesProblem({
            latitude: form.data.latitude,
            longitude: form.data.longitude,
        }) === null &&
        form.data.latitude !== '' &&
        form.data.longitude !== ''
            ? [Number(form.data.latitude), Number(form.data.longitude)]
            : null;
    const concern: ConcernLevel = editing
        ? flashpoint.concern_level.value
        : form.data.concern_level;
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
                          color: CONCERN_COLOURS[concern],
                          title: `Area: ${formatRadius(radius)} radius`,
                      },
                  ],
        // point is rebuilt each render; depend on its parts.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [point?.[0], point?.[1], radius, concern],
    );

    const chooseLga = (value: string) => {
        form.setData((data) => ({ ...data, lga: value, community: '' }));
        router.get(
            editing
                ? FlashpointController.edit(flashpoint.ulid, {
                      query: { lga: value },
                  }).url
                : FlashpointController.create({ query: { lga: value } }).url,
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
                community: data.community === '' ? null : data.community,
                ...(suggestion ? { suggestion: suggestion.ulid } : {}),
            };

            if (editing) {
                delete values.concern_level;
                delete values.status;
                delete values.assessment;
                delete values.recommended_action;
            }

            return values;
        });

        if (editing) {
            form.put(FlashpointController.update(flashpoint.ulid).url);
        } else {
            form.post(FlashpointController.store().url);
        }
    };

    const select = (
        field:
            | 'kind'
            | 'threat_type'
            | 'community'
            | 'classification'
            | 'concern_level'
            | 'status',
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

    const textarea = (
        field: 'description' | 'assessment' | 'recommended_action',
        label: string,
        hint?: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <RichTextEditor
                id={field}
                value={form.data[field]}
                onChange={(html) => form.setData(field, html)}
                limit={field === 'recommended_action' ? 5000 : 10000}
                invalid={!!form.errors[field]}
            />
            <InputError message={form.errors[field]} />
        </div>
    );

    const title = editing ? `Edit ${flashpoint.name}` : 'Record flashpoint';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-6xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">{title}</h1>
                {suggestion && (
                    <p
                        className="rounded-md border border-violet-600/30 bg-violet-50 p-3 text-sm dark:bg-violet-950"
                        data-test="from-suggestion"
                    >
                        From a suggested blackspot
                        {suggestion.dominant_type
                            ? ` (mostly ${suggestion.dominant_type})`
                            : ''}
                        . Its incidents that you can see will be linked when you
                        record it.
                    </p>
                )}

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
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select('kind', 'Kind', options.kinds, null)}
                            {select(
                                'threat_type',
                                'Threat type',
                                options.threatTypes,
                                'Choose a threat type',
                            )}
                        </div>
                        <dl className="space-y-1 text-xs text-muted-foreground">
                            <div>
                                <dt className="inline font-medium">
                                    Flashpoint:
                                </dt>{' '}
                                <dd className="inline">
                                    a place where tension could erupt into
                                    violence or a serious security incident
                                    (e.g. a disputed boundary, a contested
                                    market, a site of recurring communal
                                    clashes).
                                </dd>
                            </div>
                            <div>
                                <dt className="inline font-medium">
                                    Blackspot:
                                </dt>{' '}
                                <dd className="inline">
                                    a place where incidents repeatedly happen
                                    (e.g. a kidnapping corridor, an
                                    accident-prone stretch of road, a
                                    piracy-prone creek).
                                </dd>
                            </div>
                        </dl>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                placeholder='e.g. "Abuja–Kaduna highway, km 40–55"'
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        {select(
                            'community',
                            'Community (optional)',
                            communities,
                            'None',
                            communities.length === 0,
                        )}
                        {textarea(
                            'description',
                            'Description',
                            'What makes this area a concern: parties, history, triggers.',
                        )}
                        {select(
                            'classification',
                            'Classification',
                            options.classifications,
                            null,
                        )}

                        {editing ? (
                            <p className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                                Concern level, status and recommended action
                                change through a new assessment on the
                                flashpoint page.
                            </p>
                        ) : (
                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">
                                    First assessment
                                </legend>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {select(
                                        'concern_level',
                                        'Concern level',
                                        options.concernLevels,
                                        null,
                                    )}
                                    {select(
                                        'status',
                                        'Status',
                                        options.statuses,
                                        null,
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Next review in{' '}
                                    {
                                        options.reviewDays[
                                            form.data.concern_level
                                        ]
                                    }{' '}
                                    days.
                                </p>
                                {textarea('assessment', 'Assessment')}
                                {textarea(
                                    'recommended_action',
                                    'Recommended action (optional)',
                                )}
                            </fieldset>
                        )}
                    </section>

                    <section className="space-y-4">
                        <h2 className="text-sm font-medium">Area</h2>
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
                            className="h-96"
                        />
                        <div className="grid gap-2">
                            <Label htmlFor="radius_meters">
                                Radius: {formatRadius(radius)}
                            </Label>
                            <div className="flex items-center gap-3">
                                <input
                                    id="radius_meters"
                                    type="range"
                                    min={MIN_RADIUS}
                                    max={MAX_RADIUS}
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
                                    min={MIN_RADIUS}
                                    max={MAX_RADIUS}
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
                            <p className="text-xs text-muted-foreground">
                                100 m to 50 km. New incidents inside the area
                                are flagged to its owners.
                            </p>
                            <InputError message={form.errors.radius_meters} />
                        </div>
                    </section>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Record flashpoint'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? FlashpointController.show(flashpoint.ulid)
                                    : FlashpointController.index()
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

FlashpointForm.layout = {
    breadcrumbs: [{ title: 'Flashpoints', href: FlashpointController.index() }],
};
