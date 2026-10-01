import { useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';

type Payload = { incident: string; event: string };

const EVENTS = [
    '.incident.submitted',
    '.incident.status-changed',
    '.incident.escalated',
    '.incident.updated',
];

/**
 * Refreshes an open incident page when its incident changes (Phase 5C).
 * Broadcasts carry only the incident ULID and the event name; the page
 * refetches through its normal, scoped route. Renders nothing.
 */
export default function IncidentLiveRefresh({
    incidentUlid,
    channel,
}: {
    incidentUlid: string;
    channel: string;
}) {
    useEcho<Payload>(
        channel,
        EVENTS,
        (payload) => {
            if (payload.incident === incidentUlid) {
                router.reload();
            }
        },
        [incidentUlid],
    );

    return null;
}
