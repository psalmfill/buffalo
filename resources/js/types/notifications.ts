export type NotificationSeverity = 'info' | 'warning' | 'critical';

/**
 * A notification as presented by NotificationController::present(). Payloads
 * never contain sensitive content; `url` is an internal path.
 */
export type AppNotification = {
    id: string;
    type: string | null;
    title: string | null;
    body: string | null;
    url: string | null;
    severity: NotificationSeverity;
    read_at: string | null;
    created_at: string | null;
};
