import { Head, Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import SenatorialDistrictController from '@/actions/App/Http/Controllers/Political/SenatorialDistrictController';
import Heading from '@/components/heading';
import OfficeHoldersTable from '@/components/political/office-holders-table';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { NamedRef } from '@/types/local-government';
import type { PoliticalOffice, RoleGroup } from '@/types/political';

type Props = {
    district: NamedRef & { code: string; state: NamedRef };
    lgas: (NamedRef & { chief: NamedRef | null; chairman: NamedRef[] })[];
    currentHolders: PoliticalOffice[];
    prominentByRole: RoleGroup[];
    pastHolders: PoliticalOffice[];
    canViewPolitical: boolean;
};

/** A senatorial district: its LGAs, office holders and prominent persons. */
export default function SenatorialDistrictShow({
    district,
    lgas,
    currentHolders,
    prominentByRole,
    pastHolders,
    canViewPolitical,
}: Props) {
    return (
        <>
            <Head title={district.name} />
            <div className="space-y-8 p-4">
                <Heading
                    title={district.name}
                    description={`${district.state.name} State · ${district.code}`}
                />

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">LGAs</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        LGA
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        LGA security chief
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Chairman
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {lgas.map((lga) => (
                                    <tr key={lga.ulid}>
                                        <td className="px-3 py-2 font-medium">
                                            <Link
                                                href={LgaController.show(
                                                    lga.ulid,
                                                    {
                                                        query: {
                                                            tab: 'leadership',
                                                        },
                                                    },
                                                )}
                                                className="hover:underline"
                                            >
                                                {lga.name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {lga.chief?.name ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {lga.chairman
                                                .map((c) => c.name)
                                                .join(', ') || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        LGA pages open only for LGAs within your jurisdiction.
                    </p>
                </section>

                {canViewPolitical && (
                    <>
                        <section className="space-y-3">
                            <h2 className="text-sm font-semibold">
                                Current office holders
                            </h2>
                            <OfficeHoldersTable offices={currentHolders} />
                        </section>

                        {/* Prominent persons, grouped by role type (Phase 11A.1). */}
                        <section className="space-y-4">
                            <h2 className="text-sm font-semibold">
                                Prominent persons
                            </h2>
                            {prominentByRole.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    None recorded.
                                </p>
                            )}
                            {prominentByRole.map((group) => (
                                <div
                                    key={group.type.value}
                                    className="space-y-2"
                                >
                                    <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {group.type.label}
                                    </h3>
                                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {group.persons.map((person) => (
                                            <li
                                                key={person.ulid}
                                                className="rounded-md border px-3 py-2 text-sm"
                                            >
                                                <Link
                                                    href={PersonController.show(
                                                        person.ulid,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {person.name}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {person.lga?.name}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </section>

                        <Collapsible>
                            <CollapsibleTrigger className="group flex items-center gap-1 text-sm font-semibold">
                                <ChevronDown className="size-4 transition-transform group-data-[state=closed]:-rotate-90" />
                                Past office holders ({pastHolders.length})
                            </CollapsibleTrigger>
                            <CollapsibleContent className="pt-3">
                                <OfficeHoldersTable offices={pastHolders} />
                            </CollapsibleContent>
                        </Collapsible>
                    </>
                )}
            </div>
        </>
    );
}

SenatorialDistrictShow.layout = {
    breadcrumbs: [
        {
            title: 'Senatorial districts',
            href: SenatorialDistrictController.index(),
        },
    ],
};
