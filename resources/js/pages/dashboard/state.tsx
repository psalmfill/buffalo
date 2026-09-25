import { Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import EmptyState from '@/components/empty-state';
import { useAuth } from '@/hooks/use-auth';
import { dashboard } from '@/routes';
import type { StateDashboardProps } from '@/types/dashboard';

/** State Director and Deputy State Director. */
export default function StateDashboard({
    state,
    lgas,
    map,
    placeholders,
}: StateDashboardProps) {
    const { can } = useAuth();
    const vacant = lgas.filter((lga) => lga.chief === null).length;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader
                    title={state.label}
                    subtitle={[
                        state.zone ? `${state.zone} Zone` : null,
                        `${lgas.length} LGAs`,
                        `${vacant} without an LGA Security Chief`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />

                <PlaceholderCardGrid cards={placeholders} />

                <div className="grid gap-4 xl:grid-cols-2">
                    <DashboardMap view={map} />

                    <section className="rounded-xl border bg-card">
                        <h2 className="border-b px-4 py-3 text-sm font-medium">
                            LGAs and their Security Chiefs
                        </h2>
                        {lgas.length === 0 ? (
                            <EmptyState
                                title="No LGAs"
                                description="LGAs for this state have not been imported yet."
                            />
                        ) : (
                            <div className="max-h-[360px] overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-muted/80 text-left backdrop-blur">
                                        <tr>
                                            <th className="px-4 py-2 font-medium">
                                                LGA
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Headquarters
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Security Chief
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {lgas.map((lga) => (
                                            <tr key={lga.ulid}>
                                                <td className="px-4 py-2">
                                                    {lga.name}
                                                </td>
                                                <td className="px-4 py-2 text-muted-foreground">
                                                    {lga.headquarters ?? '—'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {lga.chief === null ? (
                                                        <span className="text-amber-700 dark:text-amber-400">
                                                            Vacant
                                                        </span>
                                                    ) : can('users.view') ? (
                                                        <Link
                                                            href={UserController.show(
                                                                lga.chief.ulid,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {lga.chief.name}
                                                        </Link>
                                                    ) : (
                                                        lga.chief.name
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

StateDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
