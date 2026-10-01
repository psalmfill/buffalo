import { router } from '@inertiajs/react';
import { Send, Share2 } from 'lucide-react';
import { useState } from 'react';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import DisseminateDialog from '@/components/disseminations/disseminate-dialog';
import NoteDialog from '@/components/disseminations/note-dialog';
import DisseminationStatusBadge from '@/components/disseminations/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/time';
import type { DisseminationRequestRow, Sharing } from '@/types/disseminations';

type Props = {
    sharing: Sharing;
    /** POST URL to disseminate this record. */
    disseminateUrl: string;
    /** POST URL to request dissemination of this record. */
    requestUrl: string;
};

/**
 * "Shared with" (Phase 7A), for the owners of a threat report or flashpoint:
 * its disseminations with status and expiry, renew and revoke, the places
 * its area reaches, and LGA chiefs' requests (SD/DSD) or the request
 * button (LGA chief).
 */
export default function SharingPanel({
    sharing,
    disseminateUrl,
    requestUrl,
}: Props) {
    const [dialog, setDialog] = useState<'disseminate' | 'request' | null>(
        null,
    );
    const [actioning, setActioning] = useState<DisseminationRequestRow | null>(
        null,
    );
    const [revoking, setRevoking] = useState<string | null>(null);
    const [declining, setDeclining] = useState<string | null>(null);
    const uncovered = sharing.suggestions.filter((s) => !s.covered);
    const pending = sharing.requests.filter(
        (r) => r.status.value === 'pending',
    );

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Share2 className="size-4" /> Shared with
                    </CardTitle>
                    <CardDescription>
                        {uncovered.length > 0
                            ? `The area reaches ${uncovered.map((s) => s.name).join(', ')}, not yet shared.`
                            : 'Read-only access given to other states and LGAs.'}
                    </CardDescription>
                </div>
                <div className="flex gap-2">
                    {sharing.can.disseminate && (
                        <Button
                            size="sm"
                            onClick={() => setDialog('disseminate')}
                        >
                            <Share2 /> Disseminate
                        </Button>
                    )}
                    {sharing.can.request && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDialog('request')}
                        >
                            <Send /> Request dissemination
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {sharing.disseminations.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Not shared with anyone.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {sharing.disseminations.map((d) => (
                            <li
                                key={d.ulid}
                                className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm"
                            >
                                <div className="space-y-0.5">
                                    <p className="flex flex-wrap items-center gap-2 font-medium">
                                        {d.target}
                                        <DisseminationStatusBadge
                                            status={d.status}
                                        />
                                        {d.onward && (
                                            <span className="text-xs text-muted-foreground">
                                                forwarded
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground">
                                        By {d.disseminated_by ?? 'unknown'} on{' '}
                                        {formatDateTime(d.disseminated_at)}
                                        {d.status === 'revoked' && d.revoked_at
                                            ? ` · revoked ${formatDateTime(d.revoked_at)}`
                                            : ` · ${d.status === 'expired' ? 'expired' : 'until'} ${formatDateTime(d.expires_at)}`}
                                        {d.acknowledged_at &&
                                            ` · acknowledged by ${d.acknowledged_by ?? 'a recipient'} ${formatDateTime(d.acknowledged_at)}`}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Reason: {d.reason}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    {d.can.renew && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    DisseminationController.renew.url(
                                                        d.ulid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Renew
                                        </Button>
                                    )}
                                    {d.can.revoke && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRevoking(d.ulid)}
                                        >
                                            Revoke
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {sharing.requests.length > 0 && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-medium">
                            Dissemination requests
                        </h3>
                        <ul className="divide-y rounded-md border">
                            {sharing.requests.map((r) => (
                                <li
                                    key={r.ulid}
                                    className="flex flex-wrap items-start justify-between gap-2 p-2 text-sm"
                                >
                                    <div className="space-y-0.5">
                                        <p className="font-medium">
                                            {r.requested_by ?? 'An LGA chief'} ·{' '}
                                            {r.status.label}
                                        </p>
                                        <p className="text-muted-foreground">
                                            Suggests{' '}
                                            {r.targets
                                                .map((t) => t.name)
                                                .join(', ') ||
                                                'no specific place'}{' '}
                                            · {r.reason}
                                        </p>
                                        {r.decision_note && (
                                            <p className="text-muted-foreground">
                                                Note: {r.decision_note}
                                            </p>
                                        )}
                                    </div>
                                    {r.can_decide && (
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() => setActioning(r)}
                                            >
                                                Disseminate
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setDeclining(r.ulid)
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {pending.length > 0 && !sharing.can.disseminate && (
                            <p className="text-xs text-muted-foreground">
                                Waiting for the State Director or Deputy.
                            </p>
                        )}
                    </div>
                )}
            </CardContent>

            {dialog && (
                <DisseminateDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    mode={dialog}
                    url={dialog === 'disseminate' ? disseminateUrl : requestUrl}
                    suggestions={sharing.suggestions}
                    preselect={sharing.preselect || dialog === 'request'}
                    places={sharing.places}
                    audience={sharing.audience}
                />
            )}
            {actioning && (
                <DisseminateDialog
                    open
                    onOpenChange={(open) => !open && setActioning(null)}
                    mode="disseminate"
                    url={disseminateUrl}
                    suggestions={sharing.suggestions}
                    places={sharing.places}
                    audience={sharing.audience}
                    requestUlid={actioning.ulid}
                    initialTargets={actioning.targets}
                />
            )}
            {revoking && (
                <NoteDialog
                    open
                    onOpenChange={(open) => !open && setRevoking(null)}
                    title="Revoke sharing"
                    description="Recipients lose access at once, and so does anyone it was forwarded to."
                    confirmLabel="Revoke"
                    url={DisseminationController.revoke.url(revoking)}
                />
            )}
            {declining && (
                <NoteDialog
                    open
                    onOpenChange={(open) => !open && setDeclining(null)}
                    title="Decline the request"
                    description="The LGA chief is told, with your note."
                    confirmLabel="Decline"
                    url={DisseminationController.decline.url(declining)}
                />
            )}
        </Card>
    );
}
