import { Link } from '@inertiajs/react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PoliticalOfficeController from '@/actions/App/Http/Controllers/Political/PoliticalOfficeController';
import { officePlace, officeTerm } from '@/components/political/office-place';
import type { PoliticalOffice } from '@/types/political';

/**
 * Office holders with links to their person records, and Edit where the
 * user may update the office (PoliticalOfficePolicy::update).
 */
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

    const editable = offices.some((office) => office.can?.update);

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
                        {editable && (
                            <th className="px-3 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        )}
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
                            {editable && (
                                <td className="px-3 py-2 text-right">
                                    {office.can?.update && (
                                        <Link
                                            href={PoliticalOfficeController.edit(
                                                office.ulid,
                                            )}
                                            className="text-sm font-medium hover:underline"
                                            data-test="edit-office"
                                        >
                                            Edit
                                        </Link>
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
