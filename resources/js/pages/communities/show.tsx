import { Head, Link } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { MapView } from '@/types/dashboard';
import type { CommunityDetail } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Props = {
    community: CommunityDetail;
    marker: MapMarker | null;
    map: MapView;
    tab: 'details' | 'history';
    history: Paginated<ActivityEntry> | null;
    can: { update: boolean; delete: boolean };
};

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm whitespace-pre-line">{value ?? '—'}</dd>
        </div>
    );
}

export default function CommunityShow({
    community,
    marker,
    tab,
    history,
    can,
}: Props) {
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <Head title={community.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            {community.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {community.type.label} ·{' '}
                            <Link
                                href={LgaController.show(community.lga.ulid, {
                                    query: { tab: 'communities' },
                                })}
                                className="hover:underline"
                            >
                                {community.lga.name} LGA
                            </Link>
                            , {community.state}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={CommunityController.edit(
                                        community.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="destructive"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav
                    className="flex gap-1 border-b"
                    aria-label="Community sections"
                >
                    {(['details', 'history'] as const).map((name) => (
                        <Link
                            key={name}
                            href={CommunityController.show(community.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {name === 'details' ? 'Details' : 'History'}
                        </Link>
                    ))}
                </nav>

                {tab === 'details' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <dl className="grid content-start gap-4 rounded-lg border p-4 sm:grid-cols-2">
                            <Fact
                                label="Other names"
                                value={
                                    community.other_names.length > 0
                                        ? community.other_names.join(', ')
                                        : null
                                }
                            />
                            <Fact label="Ward" value={community.ward} />
                            <Fact
                                label="Population (estimate)"
                                value={
                                    community.population_estimate?.toLocaleString(
                                        'en-NG',
                                    ) ?? null
                                }
                            />
                            <Fact
                                label="Coordinates"
                                value={
                                    community.latitude !== null
                                        ? `${community.latitude}, ${community.longitude}`
                                        : null
                                }
                            />
                            <div className="sm:col-span-2">
                                <Fact label="Notes" value={community.notes} />
                            </div>
                        </dl>
                        {marker ? (
                            <BuffaloMap
                                center={marker.position}
                                zoom={13}
                                markers={[marker]}
                                className="h-80 w-full rounded-lg border"
                            />
                        ) : (
                            <div className="flex h-80 items-center justify-center rounded-lg border text-sm text-muted-foreground">
                                No location recorded.
                            </div>
                        )}
                    </div>
                )}

                {tab === 'history' && history && (
                    <div className="space-y-3">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${community.name}"?`}
                    description="The community is removed from lists and searches. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    url={CommunityController.destroy(community.ulid).url}
                    method="delete"
                />
            )}
        </>
    );
}

CommunityShow.layout = {
    breadcrumbs: [{ title: 'Communities', href: CommunityController.index() }],
};
