import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
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
    } | null;
    options: PoiOptions;
    map: MapView;
};

/**
 * Designate a person of interest (with risk, status and the grounds) or
 * edit a POI's details. Later status and risk changes are made on the POI
 * tab, each with a comment.
 */
export default function PoiForm({ person, poi, options, map }: Props) {
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
                        <Textarea
                            id="known_offences"
                            rows={5}
                            required
                            value={form.data.known_offences}
                            onChange={(e) =>
                                form.setData('known_offences', e.target.value)
                            }
                        />
                        <InputError message={form.errors.known_offences} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="modus_operandi">Modus operandi</Label>
                        <Textarea
                            id="modus_operandi"
                            rows={5}
                            value={form.data.modus_operandi}
                            onChange={(e) =>
                                form.setData('modus_operandi', e.target.value)
                            }
                        />
                        <InputError message={form.errors.modus_operandi} />
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
