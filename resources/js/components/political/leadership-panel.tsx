import { Link } from '@inertiajs/react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import OfficeHoldersTable from '@/components/political/office-holders-table';
import type { NamedRef } from '@/types/local-government';
import type { LgaLeadership } from '@/types/political';

function PersonLink({ person }: { person: NamedRef }) {
    return (
        <Link
            href={PersonController.show(person.ulid)}
            className="font-medium hover:underline"
        >
            {person.name}
        </Link>
    );
}

/** The LGA page's Leadership tab. */
export default function LeadershipPanel({ data }: { data: LgaLeadership }) {
    return (
        <div className="space-y-8">
            <section className="space-y-3">
                <h3 className="text-sm font-semibold">
                    Chairman and vice chairman
                </h3>
                <OfficeHoldersTable
                    offices={[...data.chairman, ...data.viceChairman]}
                    empty="No current chairman or vice chairman recorded."
                />
            </section>

            <section className="space-y-3">
                <h3 className="text-sm font-semibold">Councillors by ward</h3>
                <OfficeHoldersTable
                    offices={data.councillors}
                    empty="No current councillors recorded."
                />
            </section>

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">
                        Traditional rulers
                    </h3>
                    {data.traditionalRulers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None recorded.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {data.traditionalRulers.map((row) => (
                                <li
                                    key={row.community.ulid}
                                    className="px-3 py-2"
                                >
                                    <PersonLink person={row.person} />
                                    <div className="text-xs text-muted-foreground">
                                        <Link
                                            href={CommunityController.show(
                                                row.community.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {row.community.name}
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Religious leaders</h3>
                    {data.religiousLeaders.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None recorded.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {data.religiousLeaders.map((row) => (
                                <li
                                    key={row.institution.ulid}
                                    className="px-3 py-2"
                                >
                                    <PersonLink person={row.person} />
                                    <div className="text-xs text-muted-foreground">
                                        {row.institution.name} ·{' '}
                                        {row.institution.religion}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Community leaders</h3>
                    {data.communityLeaders.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None recorded.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {data.communityLeaders.map((person) => (
                                <li key={person.ulid} className="px-3 py-2">
                                    <PersonLink person={person} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );
}
