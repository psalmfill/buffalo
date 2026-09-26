import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import SecurityNoteController from '@/actions/App/Http/Controllers/CommunityInformation/SecurityNoteController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import NoteStatusBadge from '@/components/community-information/note-status-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    InformationCan,
    InformationOptions,
    NotesTab,
} from '@/types/community-information';

/**
 * Community security notes the user may see (jurisdiction and
 * classification both apply on the server).
 */
export default function SecurityNotesPanel({
    lgaUlid,
    communityUlid,
    baseUrl,
    data,
    options,
    can,
}: {
    lgaUlid: string;
    communityUlid?: string;
    baseUrl: string;
    data: NotesTab;
    options: InformationOptions;
    can: InformationCan;
}) {
    const { values, apply, reset } = useFilters(baseUrl, data.filters);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex-1">
                    <FilterBar onApply={() => apply()} onReset={reset}>
                        <div className="grid gap-2">
                            <Label htmlFor="note-status">Status</Label>
                            <NativeSelect
                                id="note-status"
                                value={values.status}
                                onChange={(event) =>
                                    apply({ status: event.target.value })
                                }
                            >
                                <option value="">All statuses</option>
                                {options.noteStatuses.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="note-category">Category</Label>
                            <NativeSelect
                                id="note-category"
                                value={values.category}
                                onChange={(event) =>
                                    apply({ category: event.target.value })
                                }
                            >
                                <option value="">All categories</option>
                                {options.noteCategories.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </FilterBar>
                </div>
                {can.addSecurityNote && (
                    <Button asChild>
                        <Link
                            href={SecurityNoteController.create(lgaUlid, {
                                query: communityUlid
                                    ? { community: communityUlid }
                                    : {},
                            })}
                        >
                            <Plus /> Add security note
                        </Link>
                    </Button>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Note</th>
                            <th className="px-3 py-2 font-medium">
                                Classification
                            </th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="px-3 py-2 font-medium">Category</th>
                            {!communityUlid && (
                                <th className="px-3 py-2 font-medium">
                                    Community
                                </th>
                            )}
                            <th className="px-3 py-2 font-medium">Observed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {data.list.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="p-0">
                                    <EmptyState
                                        title="No security notes"
                                        description="None recorded, none you may see, or none match these filters."
                                    />
                                </td>
                            </tr>
                        )}
                        {data.list.data.map((note) => (
                            <tr key={note.ulid}>
                                <td className="px-3 py-2">
                                    <Link
                                        href={SecurityNoteController.show(
                                            note.ulid,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {note.title}
                                    </Link>
                                </td>
                                <td className="px-3 py-2">
                                    <ClassificationBadge
                                        level={note.classification.value}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    <NoteStatusBadge
                                        status={note.status.value}
                                        label={note.status.label}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    {note.category.label}
                                </td>
                                {!communityUlid && (
                                    <td className="px-3 py-2">
                                        {note.community.name}
                                    </td>
                                )}
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {note.observed_at}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination page={data.list} />
        </div>
    );
}
