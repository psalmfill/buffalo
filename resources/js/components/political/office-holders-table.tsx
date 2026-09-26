import { Link } from '@inertiajs/react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import { officePlace, officeTerm } from '@/components/political/office-place';
import type { PoliticalOffice } from '@/types/political';

/** Office holders with links to their person records. */
export default function OfficeHoldersTable({
    offices,
    empty = 'None recorded.',
}: {
    offices: PoliticalOffice[];
    empty?: string;
}) {
    if (offices.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Office</th>
                        <th className="px-3 py-2 font-medium">Holder</th>
                        <th className="px-3 py-2 font-medium">Place</th>
                        <th className="px-3 py-2 font-medium">Party</th>
                        <th className="px-3 py-2 font-medium">Term</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {offices.map((office) => (
                        <tr key={office.ulid}>
                            <td className="px-3 py-2">{office.title}</td>
                            <td className="px-3 py-2 font-medium">
                                {office.person ? (
                                    <Link
                                        href={PersonController.show(
                                            office.person.ulid,
                                        )}
                                        className="hover:underline"
                                    >
                                        {office.person.name}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </td>
                            <td className="px-3 py-2">{officePlace(office)}</td>
                            <td className="px-3 py-2">{office.party ?? '—'}</td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {officeTerm(office)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
