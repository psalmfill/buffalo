import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import FormationMap from '@/components/security/formation-map';
import { Button } from '@/components/ui/button';
import type { MapView } from '@/types/dashboard';
import type { FormationRow } from '@/types/formations';

export type SecurityPresenceTab = {
    formations: FormationRow[];
    map: MapView;
};

/** The LGA page's "Security presence" tab: formations in the LGA. */
export default function SecurityPresencePanel({
    lgaUlid,
    data,
    canAdd,
}: {
    lgaUlid: string;
    data: SecurityPresenceTab;
    canAdd: boolean;
}) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    Formations of security agencies located in this LGA.
                </p>
                {canAdd && (
                    <Button asChild size="sm">
                        <Link
                            href={FormationController.create({
                                query: { lga: lgaUlid },
                            })}
                        >
                            <Plus /> Add formation
                        </Link>
                    </Button>
                )}
            </div>

            <FormationMap
                formations={data.formations}
                center={data.map.center}
                zoom={data.map.zoom}
                outline={data.map.outline}
                className="h-80"
            />

            {data.formations.length === 0 ? (
                <EmptyState
                    title="No formations"
                    description="No formations recorded in this LGA that you can view."
                />
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Formation
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Agency
                                </th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Strength
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {data.formations.map((f) => (
                                <tr key={f.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={FormationController.show(
                                                f.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {f.name}
                                        </Link>
                                        {f.is_temporary && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                temporary
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.agency.short_name || f.agency.name}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.formation_type.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.status.label}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {f.personnel_strength?.toLocaleString(
                                            'en-NG',
                                        ) ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={f.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
