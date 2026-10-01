import { router } from '@inertiajs/react';
import { Check, Forward, Share2 } from 'lucide-react';
import { useState } from 'react';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import DisseminateDialog from '@/components/disseminations/disseminate-dialog';
import DisseminationEndedWatcher from '@/components/disseminations/dissemination-ended-watcher';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/time';
import type { SharedBanner as Banner } from '@/types/disseminations';

/**
 * For recipients of a disseminated threat report or flashpoint (Phase 7A):
 * who shared it, when and why; read-only. Acknowledge, and (a recipient
 * state's SD/DSD) forward it to LGAs of their own state. The page closes
 * when the sharing ends.
 */
export default function SharedBanner({ banner }: { banner: Banner }) {
    const [forwarding, setForwarding] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <div
            role="status"
            className="space-y-2 rounded-md border border-cyan-600/50 bg-cyan-50 p-3 text-sm dark:bg-cyan-950/30"
        >
            <DisseminationEndedWatcher
                active={banner.active}
                expiresAt={banner.expires_at}
            />
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <p className="flex items-center gap-2 font-medium">
                        <Share2 className="size-4" />
                        Shared by {banner.source} ({banner.source_lga} LGA) on{' '}
                        {formatDateTime(banner.disseminated_at)} — read-only.
                    </p>
                    <p>Reason: {banner.reason}</p>
                    <p className="text-muted-foreground">
                        Shared with {banner.target} until{' '}
                        {formatDateTime(banner.expires_at)}.
                    </p>
                </div>
                <div className="flex gap-2">
                    {banner.acknowledge ? (
                        <Button
                            size="sm"
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    DisseminationController.acknowledge.url(
                                        banner.acknowledge as string,
                                    ),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        onFinish: () => setProcessing(false),
                                    },
                                )
                            }
                        >
                            <Check /> Acknowledge
                        </Button>
                    ) : (
                        banner.acknowledged && (
                            <span className="flex items-center gap-1 text-muted-foreground">
                                <Check className="size-4" /> Acknowledged
                            </span>
                        )
                    )}
                    {banner.onward && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setForwarding(true)}
                        >
                            <Forward /> Forward to LGAs
                        </Button>
                    )}
                </div>
            </div>
            {banner.onward && forwarding && (
                <DisseminateDialog
                    open
                    onOpenChange={setForwarding}
                    mode="onward"
                    url={DisseminationController.onward.url(banner.onward.ulid)}
                    suggestions={[]}
                    lgas={banner.onward.lgas}
                    audience={banner.onward.audience}
                />
            )}
        </div>
    );
}
