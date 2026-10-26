import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import GoLiveController from '@/actions/App/Http/Controllers/System/GoLiveController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, timeAgo } from '@/lib/time';

type Item = {
    section: 'technical' | 'decision';
    key: string;
    label: string;
    // manual: checked outside BUFFALO (the host nginx's certificate in
    // edge mode proxy); never blocks go-live.
    status: 'pass' | 'fail' | 'recorded' | 'missing' | 'manual';
    detail: string;
};

type Decision = {
    value: string;
    label: string;
    outcomes: { value: string; label: string }[];
    hint: string;
};

type HistoryEntry = {
    ulid: string;
    decision: string;
    outcome: string;
    details: string;
    client_representative: string;
    decided_on: string;
    reference_version: string | null;
    recorded_by: string;
    recorded_at: string;
};

type Props = {
    check: {
        passed: boolean;
        checked_at: string;
        version: string;
        items: Item[];
    } | null;
    decisions: Decision[];
    history: HistoryEntry[];
};

const STATUS_LABELS: Record<Item['status'], string> = {
    pass: 'Pass',
    fail: 'Fail',
    recorded: 'Recorded',
    missing: 'Not recorded',
    manual: 'Check on the host',
};

function StatusBadge({ status }: { status: Item['status'] }) {
    if (status === 'manual') {
        return <Badge variant="outline">{STATUS_LABELS[status]}</Badge>;
    }

    const ok = status === 'pass' || status === 'recorded';

    return (
        <Badge
            variant={ok ? 'default' : 'destructive'}
            className={ok ? 'bg-emerald-600 text-white' : undefined}
        >
            {STATUS_LABELS[status]}
        </Badge>
    );
}

function ItemList({
    items,
    action,
}: {
    items: Item[];
    action?: (item: Item) => React.ReactNode;
}) {
    return (
        <ul className="divide-y rounded-lg border">
            {items.map((item) => (
                <li
                    key={item.key}
                    className="flex flex-wrap items-start justify-between gap-3 p-3"
                    data-test={`golive-${item.key}`}
                >
                    <div className="min-w-0 flex-1">
                        <div className="text-sm font-medium">{item.label}</div>
                        <div className="text-xs text-muted-foreground">
                            {item.detail}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <StatusBadge status={item.status} />
                        {action?.(item)}
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * Go-live (Phase 9B.6): the latest go-live check, read-only (it runs daily
 * and with `php artisan buffalo:go-live:check`), and the client decisions,
 * recorded with step-up.
 */
export default function GoLive({ check, decisions, history }: Props) {
    const [open, setOpen] = useState<Decision | null>(null);
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
        decision: '',
        outcome: '',
        details: '',
        client_representative: '',
        decided_on: today,
    });

    const start = (decision: Decision) => {
        form.setData({
            decision: decision.value,
            outcome: decision.outcomes[0]?.value ?? '',
            details: '',
            client_representative: '',
            decided_on: today,
        });
        form.clearErrors();
        setOpen(decision);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(GoLiveController.store().url, {
            preserveScroll: true,
            onSuccess: () => setOpen(null),
        });
    };

    const technical =
        check?.items.filter((i) => i.section === 'technical') ?? [];
    const decisionItems =
        check?.items.filter((i) => i.section === 'decision') ?? [];

    return (
        <>
            <Head title="Go-live" />
            <div className="space-y-6 p-4">
                <Heading
                    help={{ topic: 'roles/super-admin', section: 'go-live' }}
                    title="Go-live"
                    description="Every technical check must pass and every client decision must be recorded before go-live. The check runs daily; run php artisan buffalo:go-live:check for an immediate result and a report."
                />

                {check === null ? (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        The go-live check has not run yet.
                    </p>
                ) : (
                    <div
                        className={
                            check.passed
                                ? 'flex items-center gap-3 rounded-lg border border-emerald-600/40 bg-emerald-600/10 p-4'
                                : 'flex items-center gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4'
                        }
                        data-test="golive-result"
                    >
                        {check.passed ? (
                            <CheckCircle2 className="size-6 text-emerald-600" />
                        ) : (
                            <CircleAlert className="size-6 text-destructive" />
                        )}
                        <div className="text-sm">
                            <div className="font-medium">
                                {check.passed
                                    ? 'Ready to go live'
                                    : `Not ready: ${check.items.filter((i) => i.status === 'fail' || i.status === 'missing').length} item(s) outstanding`}
                            </div>
                            <div className="text-muted-foreground">
                                Version {check.version}, checked{' '}
                                {timeAgo(check.checked_at)} (
                                {formatDateTime(check.checked_at)}).
                            </div>
                        </div>
                    </div>
                )}

                <section className="space-y-3">
                    <h2 className="text-sm font-medium">Technical checks</h2>
                    {technical.length > 0 ? (
                        <ItemList items={technical} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No result yet.
                        </p>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-sm font-medium">Client decisions</h2>
                    <ItemList
                        items={
                            decisionItems.length > 0
                                ? decisionItems
                                : decisions.map((d) => ({
                                      section: 'decision' as const,
                                      key: d.value,
                                      label: d.label,
                                      status: 'missing' as const,
                                      detail: 'Not checked yet.',
                                  }))
                        }
                        action={(item) => {
                            const decision = decisions.find(
                                (d) => d.value === item.key,
                            );

                            return (
                                decision && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => start(decision)}
                                        data-test={`record-${decision.value}`}
                                    >
                                        Record
                                    </Button>
                                )
                            );
                        }}
                    />
                </section>

                {history.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">
                            Decision history (newest first; records are never
                            changed)
                        </h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Decision
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Outcome
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Details
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Decided by
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Recorded
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y align-top">
                                    {history.map((entry) => (
                                        <tr key={entry.ulid}>
                                            <td className="px-3 py-2">
                                                {entry.decision}
                                            </td>
                                            <td className="px-3 py-2">
                                                {entry.outcome}
                                                {entry.reference_version && (
                                                    <div className="text-xs text-muted-foreground">
                                                        Version{' '}
                                                        {
                                                            entry.reference_version
                                                        }
                                                    </div>
                                                )}
                                            </td>
                                            <td className="max-w-md px-3 py-2 whitespace-pre-line">
                                                {entry.details}
                                            </td>
                                            <td className="px-3 py-2">
                                                {entry.client_representative}
                                                <div className="text-xs text-muted-foreground">
                                                    {entry.decided_on}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                                {entry.recorded_by}
                                                <br />
                                                {formatDateTime(
                                                    entry.recorded_at,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>

            <Dialog
                open={open !== null}
                onOpenChange={(o) => !o && setOpen(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{open?.label}</DialogTitle>
                            <DialogDescription>
                                Records the client's decision. Records are never
                                changed; a new record replaces the previous one.
                                You will be asked for your authenticator code.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label htmlFor="outcome">Outcome</Label>
                            <NativeSelect
                                id="outcome"
                                value={form.data.outcome}
                                onChange={(e) =>
                                    form.setData('outcome', e.target.value)
                                }
                            >
                                {open?.outcomes.map((outcome) => (
                                    <option
                                        key={outcome.value}
                                        value={outcome.value}
                                    >
                                        {outcome.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.outcome} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="details">Details</Label>
                            <Textarea
                                id="details"
                                rows={4}
                                value={form.data.details}
                                onChange={(e) =>
                                    form.setData('details', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {open?.hint}
                            </p>
                            <InputError message={form.errors.details} />
                        </div>

                        <div className="grid gap-2 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="client_representative">
                                    Decided by (client)
                                </Label>
                                <Input
                                    id="client_representative"
                                    placeholder="Name and role"
                                    value={form.data.client_representative}
                                    onChange={(e) =>
                                        form.setData(
                                            'client_representative',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.client_representative}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="decided_on">Date</Label>
                                <Input
                                    id="decided_on"
                                    type="date"
                                    max={today}
                                    value={form.data.decided_on}
                                    onChange={(e) =>
                                        form.setData(
                                            'decided_on',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.decided_on} />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Record decision
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

GoLive.layout = {
    breadcrumbs: [{ title: 'Go-live', href: GoLiveController.index() }],
};
