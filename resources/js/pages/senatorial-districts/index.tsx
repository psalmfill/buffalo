import { Head, Link } from '@inertiajs/react';
import SenatorialDistrictController from '@/actions/App/Http/Controllers/Political/SenatorialDistrictController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import type { NamedRef } from '@/types/local-government';

type District = {
    ulid: string;
    name: string;
    code: string;
    state: NamedRef;
    lga_count: number;
    senators: NamedRef[];
};

/** Senatorial districts within the user's jurisdiction. */
export default function SenatorialDistrictsIndex({
    districts,
}: {
    districts: District[];
}) {
    return (
        <>
            <Head title="Senatorial districts" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Senatorial districts"
                    description="Districts within your jurisdiction, their LGAs and current senators."
                />
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    District
                                </th>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    LGAs
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Current senator
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {districts.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="p-0">
                                        <EmptyState
                                            title="No senatorial districts"
                                            description="None within your jurisdiction."
                                        />
                                    </td>
                                </tr>
                            )}
                            {districts.map((district) => (
                                <tr key={district.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={SenatorialDistrictController.show(
                                                district.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {district.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        {district.state.name}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {district.lga_count}
                                    </td>
                                    <td className="px-3 py-2">
                                        {district.senators
                                            .map((s) => s.name)
                                            .join(', ') || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

SenatorialDistrictsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Senatorial districts',
            href: SenatorialDistrictController.index(),
        },
    ],
};
