import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
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
import type { ClassificationLevel } from '@/types/auth';
import type { MapView } from '@/types/dashboard';
import type { NamedRef, Option } from '@/types/local-government';
import type { OrganizationOptions, SiteDetail } from '@/types/organizations';

type Props = {
    organization: NamedRef;
    site: SiteDetail | null;
    lga: NamedRef | null;
    lgas: Option[];
    communities: Option[];
    providers: (Option & { private_security: boolean })[];
    options: OrganizationOptions;
    map: MapView;
};

/** Add or edit a site of an organization. The LGA is chosen once. */
export default function OrganizationSiteForm({
    organization,
    site,
    lga,
    lgas,
    communities,
    providers,
    options,
    map,
}: Props) {
    const editing = site !== null;
    const form = useForm({
        lga: lga?.ulid ?? '',
        name: site?.name ?? '',
        site_type: site?.site_type.value ?? '',
        community: site?.community?.ulid ?? '',
        address: site?.address ?? '',
        latitude: site?.latitude?.toString() ?? '',
        longitude: site?.longitude?.toString() ?? '',
        employees_estimate: site?.employees_estimate?.toString() ?? '',
        security_relevance: site?.security_relevance.value ?? 'medium',
        has_private_security: site?.has_private_security ?? false,
        security_provider: site?.security_provider?.ulid ?? '',
        security_notes: site?.security_notes ?? '',
        status: site?.status.value ?? 'active',
        classification: (site?.classification.value ??
            'restricted') as ClassificationLevel,
    });
    const provider = providers.find(
        (p) => p.value === form.data.security_provider,
    );

    const chooseLga = (value: string) => {
        form.setData((data) => ({ ...data, lga: value, community: '' }));
        router.get(
            OrganizationSiteController.create(organization.ulid, {
                query: { lga: value },
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

        if (
            coordinatesProblem({
                latitude: form.data.latitude,
                longitude: form.data.longitude,
            }) !== null
        ) {
            return;
        }

        form.transform((data) => {
            const values: Record<string, unknown> = {
                ...data,
                community: data.community === '' ? null : data.community,
                security_provider:
                    data.security_provider === ''
                        ? null
                        : data.security_provider,
            };

            if (editing) {
                delete values.lga;
            }

            return values;
        });

        if (editing) {
            form.put(OrganizationSiteController.update(site.ulid).url);
        } else {
            form.post(OrganizationSiteController.store(organization.ulid).url);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const select = (
        field:
            | 'site_type'
            | 'community'
            | 'security_relevance'
            | 'status'
            | 'classification'
            | 'security_provider',
        label: string,
        items: Option[],
        placeholder: string | null,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
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
            <InputError message={errors[field]} />
        </div>
    );
    const text = (
        field: 'name' | 'address' | 'employees_estimate',
        label: string,
        type = 'text',
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input
                id={field}
                type={type}
                min={type === 'number' ? 0 : undefined}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            />
            <InputError message={errors[field]} />
        </div>
    );

    return (
        <>
            <Head
                title={
                    editing
                        ? `Edit ${site.name}`
                        : `Add site: ${organization.name}`
                }
            />
            <form onSubmit={submit} className="max-w-5xl space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        {editing ? `Edit ${site.name}` : 'Add site'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {organization.name}
                        {editing
                            ? ` · ${site.lga.name} LGA, ${site.state.name} State`
                            : ''}
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-4">
                        {!editing && (
                            <div className="grid gap-2">
                                <Label htmlFor="lga">LGA</Label>
                                <NativeSelect
                                    id="lga"
                                    value={form.data.lga}
                                    onChange={(e) => chooseLga(e.target.value)}
                                >
                                    <option value="">Choose an LGA</option>
                                    {lgas.map((item) => (
                                        <option
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.lga} />
                            </div>
                        )}
                        {text('name', 'Name (e.g. "Bonny Export Terminal")')}
                        {select(
                            'site_type',
                            'Type',
                            options.siteTypes,
                            'Choose a type',
                        )}
                        {select(
                            'community',
                            'Community (optional)',
                            communities,
                            'None',
                        )}
                        {text('address', 'Address')}
                        {text(
                            'employees_estimate',
                            'Employees (estimate)',
                            'number',
                        )}
                        {select(
                            'security_relevance',
                            'Security relevance',
                            options.relevance,
                            null,
                        )}
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="has_private_security"
                                checked={form.data.has_private_security}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'has_private_security',
                                        checked === true,
                                    )
                                }
                            />
                            <Label htmlFor="has_private_security">
                                Has private security
                            </Label>
                        </div>
                        {select(
                            'security_provider',
                            'Private security provider (from the directory)',
                            providers,
                            'None',
                        )}
                        {provider && !provider.private_security && (
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                This organization is not in the private security
                                sector. Check that it is the right provider.
                            </p>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="security_notes">
                                Security notes (threats, past incidents,
                                vulnerabilities)
                            </Label>
                            <Textarea
                                id="security_notes"
                                rows={5}
                                value={form.data.security_notes}
                                onChange={(e) =>
                                    form.setData(
                                        'security_notes',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={errors.security_notes} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select(
                                'status',
                                'Status',
                                options.siteStatuses,
                                null,
                            )}
                            {select(
                                'classification',
                                'Classification',
                                options.classifications,
                                null,
                            )}
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Location</h2>
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
                            errors={{
                                latitude: errors.latitude,
                                longitude: errors.longitude,
                            }}
                        />
                    </section>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Add site'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? OrganizationSiteController.show(site.ulid)
                                    : OrganizationController.show(
                                          organization.ulid,
                                      )
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

OrganizationSiteForm.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
