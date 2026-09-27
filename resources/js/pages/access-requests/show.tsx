import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import AccessRequestController from '@/actions/App/Http/Controllers/AccessRequests/AccessRequestController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { ClassificationLevel } from '@/types/auth';
import type {
    AccessRequestOptions,
    AccessRequestRow,
} from '@/types/access-requests';

type Props = {
    request: AccessRequestRow & {
        reason: string;
        reference: string | null;
        decision_note: string | null;
        decided_by: string | null;
    };
    requester: {
        ulid: string;
        name: string;
        rank: string | null;
        role: string | null;
        jurisdiction: string;
    } | null;
    history: {
        total: number;
        approved: number;
        rejected: number;
        cancelled: number;
        pending: number;
    } | null;
    options: AccessRequestOptions;
    can: { decide: boolean; cancel: boolean; revoke: boolean };
};

const dateTime = (value: string | null) =>
    value ? new Date(value).toLocaleString('en-NG') : '—';

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm whitespace-pre-line">{children}</dd>
        </div>
    );
}

function DecisionForms({
    request,
    options,
}: {
    request: Props['request'];
    options: AccessRequestOptions;
}) {
    const approve = useForm({
        granted_days: String(request.requested_days),
        granted_scope: request.scope.value,
        note: '',
    });
    const reject = useForm({ note: '' });
    // Only shorter durations and narrower scopes than requested.
    const days = options.days.filter(
        (d) => Number(d.value) <= request.requested_days,
    );
    const scopes = options.scopes.filter(
        (s) => request.scope.value === 'full' || s.value === 'profile',
    );

    const submitApprove = (event: FormEvent) => {
        event.preventDefault();
        approve.transform((d) => ({
            ...d,
            granted_days: Number(d.granted_days),
            note: d.note || null,
        }));
        approve.post(AccessRequestController.approve(request.ulid).url, {
            preserveScroll: true,
        });
    };

    const submitReject = (event: FormEvent) => {
        event.preventDefault();
        reject.post(AccessRequestController.reject(request.ulid).url, {
            preserveScroll: true,
        });
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <form
                onSubmit={submitApprove}
                className="space-y-3 rounded-lg border p-4"
            >
                <h2 className="text-sm font-semibold">Approve</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="granted_days">Duration</Label>
                        <NativeSelect
                            id="granted_days"
                            value={approve.data.granted_days}
                            onChange={(e) =>
                                approve.setData('granted_days', e.target.value)
                            }
                        >
                            {days.map((d) => (
                                <option key={d.value} value={d.value}>
                                    {d.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={approve.errors.granted_days} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="granted_scope">Scope</Label>
                        <NativeSelect
                            id="granted_scope"
                            value={approve.data.granted_scope}
                            onChange={(e) =>
                                approve.setData('granted_scope', e.target.value)
                            }
                        >
                            {scopes.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={approve.errors.granted_scope} />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    {
                        options.scopes.find(
                            (s) => s.value === approve.data.granted_scope,
                        )?.description
                    }
                </p>
                <div className="grid gap-2">
                    <Label htmlFor="approve-note">Note (optional)</Label>
                    <Textarea
                        id="approve-note"
                        rows={2}
                        value={approve.data.note}
                        onChange={(e) =>
                            approve.setData('note', e.target.value)
                        }
                    />
                </div>
                <Button type="submit" disabled={approve.processing}>
                    Approve
                </Button>
            </form>
            <form
                onSubmit={submitReject}
                className="space-y-3 rounded-lg border p-4"
            >
                <h2 className="text-sm font-semibold">Reject</h2>
                <div className="grid gap-2">
                    <Label htmlFor="reject-note">Reason (required)</Label>
                    <Textarea
                        id="reject-note"
                        rows={4}
                        value={reject.data.note}
                        onChange={(e) => reject.setData('note', e.target.value)}
                    />
                    <InputError message={reject.errors.note} />
                </div>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={
                        reject.processing || reject.data.note.trim().length < 5
                    }
                >
                    Reject
                </Button>
            </form>
        </div>
    );
}

/** An access request: details, the requester's history and the decision. */
export default function AccessRequestShow({
    request,
    requester,
    history,
    options,
    can,
}: Props) {
    const [cancelling, setCancelling] = useState(false);
    const [revoking, setRevoking] = useState(false);

    return (
        <>
            <Head title="Access request" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Access request"
                        description={`A record of ${request.owning_state} State · requested ${dateTime(request.created_at)}`}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{request.status.label}</Badge>
                        {request.urgency.value === 'urgent' && (
                            <Badge variant="destructive">Urgent</Badge>
                        )}
                        {can.cancel && (
                            <Button
                                variant="outline"
                                onClick={() => setCancelling(true)}
                            >
                                Cancel request
                            </Button>
                        )}
                        {can.revoke && (
                            <Button
                                variant="destructive"
                                onClick={() => setRevoking(true)}
                            >
                                Revoke access
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    <dl className="grid content-start gap-4 rounded-lg border p-4 sm:grid-cols-2">
                        <Fact label="Requester">
                            {requester ? (
                                <>
                                    {requester.name}
                                    {requester.rank && `, ${requester.rank}`}
                                    <div className="text-xs text-muted-foreground">
                                        {requester.role} ·{' '}
                                        {requester.jurisdiction}
                                    </div>
                                </>
                            ) : (
                                `${request.requester.name} · ${request.requester.place}`
                            )}
                        </Fact>
                        <Fact label="Match">
                            {request.match_type?.label ?? '—'}
                        </Fact>
                        <div className="sm:col-span-2">
                            <Fact label="Reason">{request.reason}</Fact>
                        </div>
                        <Fact label="Reference">
                            {request.reference ?? '—'}
                        </Fact>
                        <Fact label="Requested">
                            {request.scope.label}, {request.requested_days}{' '}
                            days, {request.urgency.label.toLowerCase()}
                        </Fact>
                        <Fact label="Record">
                            {request.record ? (
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    <Link
                                        href={PersonController.show(
                                            request.record.ulid,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {request.record.name}
                                    </Link>
                                    <ClassificationBadge
                                        level={
                                            request.record.classification
                                                .value as ClassificationLevel
                                        }
                                    />
                                </span>
                            ) : (
                                <span className="text-muted-foreground">
                                    Not visible to you
                                </span>
                            )}
                        </Fact>
                        {request.escalated_at && (
                            <Fact label="Escalated">
                                {dateTime(request.escalated_at)}
                            </Fact>
                        )}
                        {request.decided_at && (
                            <Fact label="Decision">
                                {request.status.label} by{' '}
                                {request.decided_by ?? '—'},{' '}
                                {dateTime(request.decided_at)}
                                {request.granted_scope &&
                                    ` · ${request.granted_scope.label}, ${request.granted_days} days`}
                                {request.decision_note && (
                                    <div className="text-muted-foreground">
                                        “{request.decision_note}”
                                    </div>
                                )}
                            </Fact>
                        )}
                        {request.grant && (
                            <Fact label="Access">
                                {request.grant.revoked_at
                                    ? `Revoked ${dateTime(request.grant.revoked_at)}`
                                    : request.grant.active
                                      ? `Active until ${dateTime(request.grant.expires_at)}`
                                      : `Ended ${dateTime(request.grant.expires_at)}`}
                            </Fact>
                        )}
                    </dl>

                    {history && (
                        <section className="space-y-2 rounded-lg border p-4">
                            <h2 className="text-sm font-semibold">
                                Requester's requests, last 90 days
                            </h2>
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <Fact label="Total">{history.total}</Fact>
                                <Fact label="Approved">{history.approved}</Fact>
                                <Fact label="Rejected">{history.rejected}</Fact>
                                <Fact label="Cancelled">
                                    {history.cancelled}
                                </Fact>
                                <Fact label="Open">{history.pending}</Fact>
                            </dl>
                        </section>
                    )}
                </div>

                {can.decide && (
                    <DecisionForms request={request} options={options} />
                )}
            </div>

            {cancelling && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setCancelling}
                    title="Cancel this request?"
                    description="The owning state will no longer be asked to decide."
                    confirmLabel="Cancel request"
                    url={AccessRequestController.cancel(request.ulid).url}
                />
            )}
            {revoking && request.grant && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setRevoking}
                    title="Revoke this access?"
                    description="Access ends at once. The holder is notified."
                    confirmLabel="Revoke"
                    destructive
                    requireReason
                    url={
                        AccessRequestController.revokeGrant(request.grant.ulid)
                            .url
                    }
                />
            )}
        </>
    );
}

AccessRequestShow.layout = {
    breadcrumbs: [
        { title: 'Access requests', href: AccessRequestController.index() },
    ],
};
