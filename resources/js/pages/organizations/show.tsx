import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import RichTextView from '@/components/rich-text/rich-text-view';
import { SitesMap, SitesTable } from '@/components/organizations/site-parts';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { OrganizationDetail, SiteRow } from '@/types/organizations';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'history';

/** A directory entry with the sites the viewer can see. */
export default function OrganizationShow({
    organization,
    sites,
    tab,
    history,
    can,
}: {
    organization: OrganizationDetail;
    sites: SiteRow[];
    tab: Tab;
    history: Paginated<ActivityEntry> | null;
    can: { update: boolean; delete: boolean; addSite: boolean };
}) {
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <Head title={organization.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            {organization.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {organization.type.label} ·{' '}
                            {organization.sector.name} ·{' '}
                            {organization.status.label}
                            {organization.rc_number
                                ? ` · RC no. ${organization.rc_number}`
                                : ''}
                        </p>
                        {organization.other_names.length > 0 && (
                            <p className="text-sm">
                                Also known as:{' '}
                                {organization.other_names.join(', ')}
                            </p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={OrganizationController.edit(
                                        organization.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="outline"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(['overview', 'history'] as Tab[]).map((name) => (
                        <Link
                            key={name}
                            href={OrganizationController.show(
                                organization.ulid,
                                {
                                    query: { tab: name },
                                },
                            )}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === name ? 'page' : undefined}
                        >
                            {name === 'overview' ? 'Overview' : 'History'}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <>
                        {(organization.description || organization.website) && (
                            <section className="space-y-1 rounded-lg border p-4 text-sm">
                                {organization.description && (
                                    <RichTextView
                                        html={organization.description}
                                    />
                                )}
                                {organization.website && (
                                    <p className="text-muted-foreground">
                                        Website: {organization.website}
                                    </p>
                                )}
                            </section>
                        )}

                        <section className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold">
                                    Sites you can view ({sites.length})
                                </h2>
                                {can.addSite && (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={OrganizationSiteController.create(
                                                organization.ulid,
                                            )}
                                        >
                                            <Plus /> Add site
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Only sites within your jurisdiction and
                                clearance are listed.
                            </p>
                            <SitesMap sites={sites} />
                            <SitesTable
                                sites={sites}
                                showOrganization={false}
                            />
                        </section>
                    </>
                )}

                {tab === 'history' && history && (
                    <div className="space-y-2">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${organization.name}" from the directory?`}
                    description="Only an organization without sites can be deleted. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={OrganizationController.destroy(organization.ulid).url}
                />
            )}
        </>
    );
}

OrganizationShow.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
