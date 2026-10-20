import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import OrganizationLogo from '@/components/organizations/organization-logo';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types/local-government';
import type {
    ContactDetail,
    OrganizationOptions,
    OrganizationRow,
    SiteRow,
} from '@/types/organizations';
import type { Paginated } from '@/types/pagination';

type Filters = {
    q: string;
    type: string;
    sector: string;
    status: string;
    in_my_area: string;
};

type LookupResult = ContactDetail & { site: SiteRow };

/**
 * The organization directory. The search box finds organizations by name
 * (fuzzy), or contacts by phone number or email (exact, within your
 * jurisdiction).
 */
export default function OrganizationsIndex({
    organizations,
    contacts,
    lookup,
    filters,
    options,
    sectors,
    can,
}: {
    organizations: Paginated<OrganizationRow>;
    contacts: LookupResult[] | null;
    lookup: 'phone' | 'email' | null;
    filters: Filters;
    options: OrganizationOptions;
    sectors: Option[];
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        OrganizationController.index().url,
        filters,
    );

    const select = (
        field: 'type' | 'sector' | 'status',
        label: string,
        items: Option[],
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <NativeSelect
                id={`filter-${field}`}
                value={values[field]}
                onChange={(e) => apply({ [field]: e.target.value })}
            >
                <option value="">Any</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    return (
        <>
            <Head title="Organizations" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Organizations"
                        description="The national directory of companies and organizations. Sites and contacts are shown only within your jurisdiction."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={OrganizationController.create()}>
                                <Plus /> Add organization
                            </Link>
                        </Button>
                    )}
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="filter-q">
                            Name, or a contact's phone number or email
                        </Label>
                        <Input
                            id="filter-q"
                            value={values.q}
                            placeholder="e.g. Shell, 0803 555 0100, security@example.com"
                            onChange={(e) =>
                                setValues({ ...values, q: e.target.value })
                            }
                        />
                    </div>
                    {select('type', 'Type', options.types)}
                    {select('sector', 'Sector', sectors)}
                    {select('status', 'Status', options.statuses)}
                    <div className="flex items-end gap-2 pb-2">
                        <Checkbox
                            id="filter-in_my_area"
                            checked={values.in_my_area === '1'}
                            onCheckedChange={(checked) =>
                                apply({
                                    in_my_area: checked === true ? '1' : '',
                                })
                            }
                        />
                        <Label htmlFor="filter-in_my_area">
                            Has sites in my area
                        </Label>
                    </div>
                </FilterBar>

                {contacts !== null && (
                    <section className="space-y-2" aria-label="Contact lookup">
                        <h2 className="text-sm font-semibold">
                            Contacts with this{' '}
                            {lookup === 'phone' ? 'phone number' : 'email'} in
                            your jurisdiction
                        </h2>
                        {contacts.length === 0 ? (
                            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                                No contact in your jurisdiction has this{' '}
                                {lookup === 'phone' ? 'phone number' : 'email'}.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {contacts.map((c) => (
                                    <li key={c.ulid} className="p-3 text-sm">
                                        <div className="font-medium">
                                            {c.name}, {c.position}
                                        </div>
                                        <div className="text-muted-foreground">
                                            <Link
                                                href={OrganizationSiteController.show(
                                                    c.site.ulid,
                                                )}
                                                className="hover:underline"
                                            >
                                                {c.site.name}
                                            </Link>{' '}
                                            · {c.site.organization.name} ·{' '}
                                            {c.site.lga.name} LGA
                                        </div>
                                        <div className="text-xs">
                                            {[c.phone, c.email]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Each contact shown here is recorded in the access
                            log.
                        </p>
                    </section>
                )}

                {contacts === null && (
                    <>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Organization
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Sector
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Type
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Status
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            Sites you can view
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {organizations.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="p-0">
                                                <EmptyState
                                                    title="No organizations"
                                                    description="None match this search."
                                                />
                                            </td>
                                        </tr>
                                    )}
                                    {organizations.data.map((o) => (
                                        <tr key={o.ulid}>
                                            <td className="px-3 py-2">
                                                <div className="flex items-center gap-3">
                                                    <OrganizationLogo
                                                        name={o.name}
                                                        logo={o.logo}
                                                    />
                                                    <div>
                                                        <Link
                                                            href={OrganizationController.show(
                                                                o.ulid,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {o.name}
                                                        </Link>
                                                        {o.other_names.length >
                                                            0 && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {o.other_names.join(
                                                                    ', ',
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2">
                                                {o.sector.name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {o.type.label}
                                            </td>
                                            <td className="px-3 py-2">
                                                {o.status.label}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">
                                                {o.visible_sites}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination page={organizations} />
                    </>
                )}
            </div>
        </>
    );
}

OrganizationsIndex.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
