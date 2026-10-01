import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import IncidentWorkflowController from '@/actions/App/Http/Controllers/Incidents/IncidentWorkflowController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ClassificationSelect from '@/components/community-information/classification-select';
import { Field } from '@/components/incidents/editor-parts';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/time';
import type { ClassificationLevel } from '@/types/auth';
import type { TimelineEntry, WorkflowOptions } from '@/types/incidents';

/**
 * The Timeline tab (Phase 5C): status changes, follow-ups, escalation and
 * evidence in time order. Follow-ups are append-only: a correction is a
 * new update. Entries above the viewer's clearance never reach the page.
 */
export default function IncidentTimeline({
    incidentUlid,
    incidentLevel,
    entries,
    options,
    canAdd,
}: {
    incidentUlid: string;
    incidentLevel: ClassificationLevel;
    entries: TimelineEntry[];
    options: WorkflowOptions;
    canAdd: boolean;
}) {
    return (
        <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
            <ol className="space-y-3" aria-label="Timeline">
                {entries.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing yet.
                    </p>
                )}
                {entries.map((entry, index) => (
                    <li key={index} className="rounded-lg border p-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={
                                        entry.kind.startsWith('update_')
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {entry.label}
                                </Badge>
                                {entry.classification &&
                                    entry.classification.value !==
                                        'restricted' && (
                                        <ClassificationBadge
                                            level={entry.classification.value}
                                        />
                                    )}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {entry.who ?? 'System'} ·{' '}
                                {formatDateTime(entry.at)}
                            </span>
                        </div>
                        {entry.body && (
                            <p className="mt-2 whitespace-pre-line">
                                {entry.body}
                            </p>
                        )}
                    </li>
                ))}
            </ol>
            {canAdd && (
                <AddUpdate
                    incidentUlid={incidentUlid}
                    incidentLevel={incidentLevel}
                    options={options}
                />
            )}
        </div>
    );
}

function AddUpdate({
    incidentUlid,
    incidentLevel,
    options,
}: {
    incidentUlid: string;
    incidentLevel: ClassificationLevel;
    options: WorkflowOptions;
}) {
    const form = useForm<{
        type: string;
        body: string;
        classification: ClassificationLevel;
    }>({
        type: 'follow_up',
        body: '',
        classification: incidentLevel,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(IncidentWorkflowController.storeUpdate(incidentUlid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-3 self-start rounded-lg border p-4"
            aria-label="Add an update"
        >
            <h2 className="text-sm font-semibold">Add an update</h2>
            <Field id="update-type" label="Type" error={form.errors.type}>
                <NativeSelect
                    id="update-type"
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {options.updateTypes.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </Field>
            <Field id="update-body" label="Update" error={form.errors.body}>
                <Textarea
                    id="update-body"
                    rows={5}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
            </Field>
            <Field
                id="update-classification"
                label="Classification"
                error={form.errors.classification}
            >
                <ClassificationSelect
                    id="update-classification"
                    value={form.data.classification}
                    minimum={incidentLevel}
                    onChange={(level) => form.setData('classification', level)}
                />
            </Field>
            <p className="text-xs text-muted-foreground">
                Updates cannot be edited or deleted. To correct one, add
                another.
            </p>
            <Button type="submit" disabled={form.processing}>
                Add update
            </Button>
        </form>
    );
}
