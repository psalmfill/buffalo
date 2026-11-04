import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { MapView } from '@/types/dashboard';
import type { Option } from '@/types/local-government';
import type { PoiOptions } from '@/types/political';

type Props = {
    person: { ulid: string; name: string; lga: string };
    poi: {
        ulid: string;
        offence_categories: string[];
        known_offences: string;
        modus_operandi: string | null;
        latitude: number | null;
        longitude: number | null;
        last_known_location_description: string | null;
        last_sighted_at: string | null;
        criminal_record: string | null;
        height_cm: number | null;
        origin_state: string | null;
        origin_lga: string | null;
        hometown: string | null;
        marital_status: string | null;
        religion: string | null;
        political_affiliations: string | null;
        hair_colour: string | null;
        eye_colour: string | null;
        has_tribal_marks: string | null;
        tribal_marks_description: string | null;
    } | null;
    options: PoiOptions;
    lgasByState: Record<string, Option[]>;
    map: MapView;
};

const SPECIAL_HELP =
    'Record only where relevant to identification or the investigation.';

/**
 * Designate a person of interest (with risk, status and the grounds) or
 * edit a POI's details. Later status and risk changes are made on the POI
 * tab, each with a comment.
 */
export default function PoiForm({
    person,
    poi,
    options,
    lgasByState,
    map,
}: Props) {
    const [heightUnit, setHeightUnit] = useState<'cm' | 'ft'>('cm');
    const designating = poi === null;
    const form = useForm({
        offence_categories: poi?.offence_categories ?? [],
        known_offences: poi?.known_offences ?? '',
        modus_operandi: poi?.modus_operandi ?? '',
        latitude: poi?.latitude?.toString() ?? '',
        longitude: poi?.longitude?.toString() ?? '',
        last_known_location_description:
            poi?.last_known_location_description ?? '',
        last_sighted_at: poi?.last_sighted_at ?? '',
        risk_level: 'medium',
        status: 'at_large',
        comment: '',
        // Personal details (Phase 11G.4), all optional.
        criminal_record: poi?.criminal_record ?? '',
        height_cm: poi?.height_cm?.toString() ?? '',
        height_feet: '',
        height_inches: '',
        origin_state: poi?.origin_state ?? '',
        origin_lga: poi?.origin_lga ?? '',
        hometown: poi?.hometown ?? '',
        marital_status: poi?.marital_status ?? '',
        religion: poi?.religion ?? '',
        political_affiliations: poi?.political_affiliations ?? '',
        hair_colour: poi?.hair_colour ?? '',
        eye_colour: poi?.eye_colour ?? '',
        has_tribal_marks: poi?.has_tribal_marks ?? '',
        tribal_marks_description: poi?.tribal_marks_description ?? '',
    });

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

        form.transform((data) => {
            const details = {
                offence_categories: data.offence_categories,
                known_offences: data.known_offences,
                modus_operandi: data.modus_operandi || null,
                latitude: data.latitude || null,
                longitude: data.longitude || null,
                last_known_location_description:
                    data.last_known_location_description || null,
                last_sighted_at: data.last_sighted_at || null,
                criminal_record: data.criminal_record || null,
                ...(heightUnit === 'ft' && data.height_feet !== ''
                    ? {
                          height_cm: null,
                          height_feet: data.height_feet,
                          height_inches: data.height_inches || '0',
                      }
                    : { height_cm: data.height_cm || null }),
                origin_state: data.origin_state || null,
                origin_lga: data.origin_lga || null,
                hometown: data.hometown || null,
                marital_status: data.marital_status || null,
                religion: data.religion || null,
                political_affiliations: data.political_affiliations || null,
                hair_colour: data.hair_colour || null,
                eye_colour: data.eye_colour || null,
                has_tribal_marks: data.has_tribal_marks || null,
                tribal_marks_description: data.tribal_marks_description || null,
            };

            return designating
                ? {
                      ...details,
                      risk_level: data.risk_level,
                      status: data.status,
                      comment: data.comment,
                  }
                : details;
        });

        if (designating) {
            form.post(PersonOfInterestController.store(person.ulid).url);
        } else {
            form.put(PersonOfInterestController.update(poi.ulid).url);
        }
    };

    const toggleOffence = (code: string, checked: boolean) =>
        form.setData(
            'offence_categories',
            checked
                ? [...form.data.offence_categories, code]
                : form.data.offence_categories.filter((c) => c !== code),
        );

    const title = designating
        ? 'Designate as person of interest'
        : 'Edit person of interest details';
    const statuses = options.statuses.filter((s) => s.value !== 'cleared');
    const errors = form.errors as Record<string, string | undefined>;

    const select = (
        field:
            | 'marital_status'
            | 'hair_colour'
            | 'eye_colour'
            | 'has_tribal_marks'
            | 'religion',
        label: string,
        items: Option[],
        help?: string,
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            >
                <option value="">Not recorded</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
            <InputError message={errors[field]} />
        </div>
    );

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-5xl space-y-8 p-4">
                <Heading
                    title={title}
                    description={`${person.name} · ${person.lga} LGA`}
                />

                {designating && (
                    <section className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="risk_level">Risk level</Label>
                            <NativeSelect
                                id="risk_level"
                                value={form.data.risk_level}
                                onChange={(e) =>
                                    form.setData('risk_level', e.target.value)
                                }
                            >
                                {options.riskLevels.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.risk_level} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="status">Status</Label>
                            <NativeSelect
                                id="status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData('status', e.target.value)
                                }
                            >
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.status} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="comment">
                                Grounds for designation
                            </Label>
                            <Textarea
                                id="comment"
                                rows={2}
                                maxLength={1000}
                                value={form.data.comment}
                                onChange={(e) =>
                                    form.setData('comment', e.target.value)
                                }
                            />
                            <InputError message={form.errors.comment} />
                        </div>
                    </section>
                )}

                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        Offence categories
                    </legend>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {options.offenceCategories.map((category) => (
                            <div
                                key={category.value}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`offence-${category.value}`}
                                    checked={form.data.offence_categories.includes(
                                        category.value,
                                    )}
                                    onCheckedChange={(checked) =>
                                        toggleOffence(
                                            category.value,
                                            checked === true,
                                        )
                                    }
                                />
                                <Label htmlFor={`offence-${category.value}`}>
                                    {category.label}
                                </Label>
                            </div>
                        ))}
                    </div>
                    <InputError
                        message={
                            errors.offence_categories ??
                            Object.entries(errors).find(([k]) =>
                                k.startsWith('offence_categories.'),
                            )?.[1]
                        }
                    />
                </fieldset>

                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="known_offences">Known offences</Label>
                        <RichTextEditor
                            id="known_offences"
                            value={form.data.known_offences}
                            onChange={(html) =>
                                form.setData('known_offences', html)
                            }
                            limit={10000}
                            invalid={!!form.errors.known_offences}
                        />
                        <InputError message={form.errors.known_offences} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="modus_operandi">Modus operandi</Label>
                        <RichTextEditor
                            id="modus_operandi"
                            value={form.data.modus_operandi}
                            onChange={(html) =>
                                form.setData('modus_operandi', html)
                            }
                            limit={10000}
                            invalid={!!form.errors.modus_operandi}
                        />
                        <InputError message={form.errors.modus_operandi} />
                    </div>
                </section>

                <section
                    className="space-y-4"
                    aria-labelledby="personal-heading"
                >
                    <h2 id="personal-heading" className="text-sm font-semibold">
                        Personal details (optional)
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="height">Height</Label>
                            <div className="flex items-center gap-2">
                                {heightUnit === 'cm' ? (
                                    <Input
                                        id="height"
                                        type="number"
                                        min={50}
                                        max={250}
                                        className="w-28"
                                        value={form.data.height_cm}
                                        onChange={(e) =>
                                            form.setData(
                                                'height_cm',
                                                e.target.value,
                                            )
                                        }
                                    />
                                ) : (
                                    <>
                                        <Input
                                            id="height"
                                            type="number"
                                            min={1}
                                            max={8}
                                            aria-label="Feet"
                                            className="w-20"
                                            value={form.data.height_feet}
                                            onChange={(e) =>
                                                form.setData(
                                                    'height_feet',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <span className="text-sm">ft</span>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={11}
                                            aria-label="Inches"
                                            className="w-20"
                                            value={form.data.height_inches}
                                            onChange={(e) =>
                                                form.setData(
                                                    'height_inches',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <span className="text-sm">in</span>
                                    </>
                                )}
                                <NativeSelect
                                    aria-label="Height unit"
                                    value={heightUnit}
                                    onChange={(e) =>
                                        setHeightUnit(
                                            e.target.value as 'cm' | 'ft',
                                        )
                                    }
                                >
                                    <option value="cm">cm</option>
                                    <option value="ft">ft and in</option>
                                </NativeSelect>
                            </div>
                            <InputError
                                message={
                                    errors.height_cm ??
                                    errors.height_feet ??
                                    errors.height_inches
                                }
                            />
                        </div>
                        {select(
                            'marital_status',
                            'Marital status',
                            options.maritalStatuses,
                        )}
                        <div className="grid content-start gap-2">
                            <Label htmlFor="hometown">Hometown</Label>
                            <Input
                                id="hometown"
                                maxLength={255}
                                value={form.data.hometown}
                                onChange={(e) =>
                                    form.setData('hometown', e.target.value)
                                }
                            />
                            <InputError message={errors.hometown} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="origin_state">
                                State of origin
                            </Label>
                            <NativeSelect
                                id="origin_state"
                                value={form.data.origin_state}
                                onChange={(e) =>
                                    form.setData((data) => ({
                                        ...data,
                                        origin_state: e.target.value,
                                        origin_lga: '',
                                    }))
                                }
                            >
                                <option value="">Not recorded</option>
                                {options.states.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.origin_state} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="origin_lga">LGA of origin</Label>
                            <NativeSelect
                                id="origin_lga"
                                value={form.data.origin_lga}
                                disabled={form.data.origin_state === ''}
                                onChange={(e) =>
                                    form.setData('origin_lga', e.target.value)
                                }
                            >
                                <option value="">Not recorded</option>
                                {(
                                    lgasByState[form.data.origin_state] ?? []
                                ).map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <p className="text-xs text-muted-foreground">
                                Origin never changes who can see the record.
                            </p>
                            <InputError message={errors.origin_lga} />
                        </div>
                    </div>
                </section>

                <section
                    className="space-y-4"
                    aria-labelledby="physical-heading"
                >
                    <h2 id="physical-heading" className="text-sm font-semibold">
                        Physical description (optional)
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {select(
                            'hair_colour',
                            'Colour of hair',
                            options.hairColours,
                        )}
                        {select(
                            'eye_colour',
                            'Colour of eyes',
                            options.eyeColours,
                        )}
                        {select(
                            'has_tribal_marks',
                            'Tribal marks',
                            options.tribalMarks,
                            SPECIAL_HELP,
                        )}
                        <div className="grid content-start gap-2 sm:col-span-3">
                            <Label htmlFor="tribal_marks_description">
                                Tribal marks: description
                            </Label>
                            <Input
                                id="tribal_marks_description"
                                maxLength={500}
                                value={form.data.tribal_marks_description}
                                onChange={(e) =>
                                    form.setData(
                                        'tribal_marks_description',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={errors.tribal_marks_description}
                            />
                        </div>
                    </div>
                </section>

                <section className="grid gap-2">
                    <Label htmlFor="criminal_record">Criminal record</Label>
                    <p className="text-xs text-muted-foreground">
                        Past records: arrests, charges, convictions, prison
                        terms. Current suspected offences go under Known
                        offences.
                    </p>
                    <RichTextEditor
                        id="criminal_record"
                        value={form.data.criminal_record}
                        onChange={(html) =>
                            form.setData('criminal_record', html)
                        }
                        limit={20000}
                        invalid={!!errors.criminal_record}
                    />
                    <InputError message={errors.criminal_record} />
                </section>

                <section
                    className="space-y-4 rounded-lg border border-amber-500/50 p-4"
                    aria-labelledby="sensitive-heading"
                >
                    <h2
                        id="sensitive-heading"
                        className="text-sm font-semibold"
                    >
                        Sensitive details (optional)
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {SPECIAL_HELP} Religion and political affiliations are
                        stored encrypted, cannot be searched, and never appear
                        in lists, exports or printed reports.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {select('religion', 'Religion', options.religions)}
                        <div className="grid content-start gap-2">
                            <Label htmlFor="political_affiliations">
                                Political affiliations
                            </Label>
                            <Input
                                id="political_affiliations"
                                maxLength={1000}
                                value={form.data.political_affiliations}
                                onChange={(e) =>
                                    form.setData(
                                        'political_affiliations',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={errors.political_affiliations}
                            />
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="grid content-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="last_known_location_description">
                                Last known location
                            </Label>
                            <Textarea
                                id="last_known_location_description"
                                rows={3}
                                value={
                                    form.data.last_known_location_description
                                }
                                onChange={(e) =>
                                    form.setData(
                                        'last_known_location_description',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={
                                    form.errors.last_known_location_description
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="last_sighted_at">
                                Last sighted
                            </Label>
                            <Input
                                id="last_sighted_at"
                                type="datetime-local"
                                value={form.data.last_sighted_at}
                                onChange={(e) =>
                                    form.setData(
                                        'last_sighted_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.last_sighted_at} />
                        </div>
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
                        outline={map.outline}
                        errors={{
                            latitude: form.errors.latitude,
                            longitude: form.errors.longitude,
                        }}
                    />
                </section>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {designating ? 'Designate' : 'Save'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={PersonController.show(person.ulid, {
                                query: { tab: 'poi' },
                            })}
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

PoiForm.layout = {
    breadcrumbs: [
        {
            title: 'Persons of interest',
            href: PersonOfInterestController.index(),
        },
    ],
};
