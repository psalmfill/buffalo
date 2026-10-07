import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import GoLiveController from '@/actions/App/Http/Controllers/System/GoLiveController';
import SystemHealthController from '@/actions/App/Http/Controllers/System/SystemHealthController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime, timeAgo } from '@/lib/time';

type Task = { ok: boolean; at: string; message: string } | null;
type Backup = {
    label: string;
    finished_at: string;
    seconds: number;
    size: number;
    repo_size: number;
} | null;

type Report = {
    generated_at: string;
    services: { name: string; ok: boolean; detail: string }[];
    queues: {
        queues: {
            name: string;
            size: number | null;
            wait_seconds: number | null;
        }[];
        failed_24h: number;
        failed_total: number;
    };
    scheduler: {
        last_run: string | null;
        runs: {
            command: string;
            ok: boolean | null;
            at: string;
            seconds: number | null;
            detail: string | null;
        }[];
    };
    backups: {
        available: boolean;
        database: {
            newest_full: Backup;
            newest_diff: Backup;
            count: number;
            wal_last_archived_at: string | null;
            wal_oldest_waiting_seconds: number | null;
            reported_at: string | null;
            check: Task;
            verify: Task;
            last_backup: Task;
            repo1_bytes: number | null;
            repo2_bytes: number | null;
        };
        evidence: {
            last_backup: Task;
            snapshots: number;
            newest_snapshot_at: string | null;
            check: Task;
            check_data: Task;
            repo1_bytes: number | null;
            repo2_bytes: number | null;
        };
        repository_disk: {
            free: number;
            total: number;
            used_percent: number;
        } | null;
        drill: {
            at: string | null;
            result: string;
            seconds: number;
            within_rto: boolean;
            mode: string;
        } | null;
    };
    storage: {
        disks: {
            name: string;
            path: string | null;
            free: number | null;
            total: number | null;
            used_percent: number | null;
        }[];
        database_bytes: number;
        largest_tables: { name: string; bytes: number }[];
    };
    audit: {
        last_verify: {
            ok: boolean | null;
            at: string;
            detail: string | null;
        } | null;
        anchors: {
            table: string;
            seq: number;
            hash: string;
            at: string | null;
        }[];
    };
    keys: { name: string; fingerprint: string; activated_at: string | null }[];
    certificate: {
        configured: boolean;
        expires_at: string | null;
        days_left: number | null;
    };
    alerts: {
        key: string;
        severity: 'warning' | 'critical';
        title: string;
        detail: string;
        raised_at: string;
    }[];
    golive: {
        passed: boolean;
        checked_at: string;
        outstanding: number;
    } | null;
};

function State({
    ok,
    label,
}: {
    ok: boolean | null | undefined;
    label?: string;
}) {
    if (ok === null || ok === undefined) {
        return <Badge variant="secondary">{label ?? 'Unknown'}</Badge>;
    }

    return ok ? (
        <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
            {label ?? 'OK'}
        </Badge>
    ) : (
        <Badge variant="destructive">{label ?? 'Failed'}</Badge>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-2 border-b py-1.5 text-sm last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{children}</span>
        </div>
    );
}

function when(iso: string | null | undefined): string {
    return iso ? `${formatDateTime(iso)} (${timeAgo(iso)})` : 'Never';
}

function TaskRow({ label, task }: { label: string; task: Task }) {
    return (
        <Row label={label}>
            {task ? (
                <>
                    <State ok={task.ok} /> {when(task.at)}
                    {!task.ok && (
                        <div className="text-xs text-destructive">
                            {task.message}
                        </div>
                    )}
                </>
            ) : (
                'Not run yet'
            )}
        </Row>
    );
}

function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/**
 * System health (Phase 8C.4): for super admins. No intelligence content:
 * services, queues, scheduler, backups, storage, audit chains, key
 * fingerprints, certificate and open alerts.
 */
export default function SystemHealth({ report }: { report: Report }) {
    const { backups, storage, audit } = report;
    const db = backups.database;
    const evidence = backups.evidence;

    return (
        <>
            <Head title="System health" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{
                            topic: 'roles/super-admin',
                            section: 'monitoring',
                        }}
                        title="System health"
                        description={`Generated ${formatDateTime(report.generated_at)}. Alerts are sent to super admins once per condition until it clears.`}
                    />
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.reload({ only: ['report'] })}
                    >
                        <RefreshCw className="size-4" /> Refresh
                    </Button>
                </div>

                <Card data-test="health-golive">
                    <CardHeader>
                        <CardTitle>Go-live check</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span>
                            {report.golive === null
                                ? 'Not run yet.'
                                : report.golive.passed
                                  ? `Ready to go live (checked ${timeAgo(report.golive.checked_at)}).`
                                  : `Not ready: ${report.golive.outstanding} item(s) outstanding (checked ${timeAgo(report.golive.checked_at)}).`}
                        </span>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={GoLiveController.index()}>
                                Open go-live
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                <Card data-test="health-alerts">
                    <CardHeader>
                        <CardTitle>Open alerts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {report.alerts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No open alerts.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {report.alerts.map((a) => (
                                    <li
                                        key={a.key}
                                        className="rounded-md border p-3 text-sm"
                                    >
                                        <div className="flex items-center gap-2 font-medium">
                                            <Badge
                                                variant={
                                                    a.severity === 'critical'
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {a.severity === 'critical'
                                                    ? 'Critical'
                                                    : 'Warning'}
                                            </Badge>
                                            {a.title}
                                        </div>
                                        <p className="mt-1 text-muted-foreground">
                                            {a.detail}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Since {when(a.raised_at)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Services</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {report.services.map((s) => (
                                <Row key={s.name} label={s.name}>
                                    <State ok={s.ok} />{' '}
                                    <span className="text-xs text-muted-foreground">
                                        {s.detail}
                                    </span>
                                </Row>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Queues and scheduler</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {report.queues.queues.map((q) => (
                                <Row key={q.name} label={`Queue: ${q.name}`}>
                                    {q.size ?? '—'} waiting
                                    {q.wait_seconds !== null &&
                                        `, longest wait ${Math.round(q.wait_seconds / 60)} min`}
                                </Row>
                            ))}
                            <Row label="Failed jobs (24 hours / total)">
                                {report.queues.failed_24h} /{' '}
                                {report.queues.failed_total}
                            </Row>
                            <Row label="Scheduler last ran">
                                {when(report.scheduler.last_run)}
                            </Row>
                            <details className="mt-2 text-sm">
                                <summary className="cursor-pointer text-muted-foreground">
                                    Last run of each scheduled command (
                                    {report.scheduler.runs.length})
                                </summary>
                                {report.scheduler.runs.map((r) => (
                                    <Row key={r.command} label={r.command}>
                                        <State ok={r.ok} /> {timeAgo(r.at)}
                                        {r.seconds !== null &&
                                            ` · ${r.seconds}s`}
                                    </Row>
                                ))}
                            </details>
                        </CardContent>
                    </Card>

                    <Card data-test="health-backups">
                        <CardHeader>
                            <CardTitle>Database backups (pgBackRest)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {!backups.available && (
                                <p className="mb-2 text-sm text-destructive">
                                    Backup status is not available.
                                </p>
                            )}
                            <Row label="Newest full">
                                {db.newest_full
                                    ? `${when(db.newest_full.finished_at)} · ${db.newest_full.label}`
                                    : 'None'}
                            </Row>
                            <Row label="Newest differential">
                                {db.newest_diff
                                    ? `${when(db.newest_diff.finished_at)} · ${db.newest_diff.label}`
                                    : 'None'}
                            </Row>
                            <Row label="WAL last archived">
                                {when(db.wal_last_archived_at)}
                                {db.wal_oldest_waiting_seconds
                                    ? ` · oldest waiting ${Math.round(db.wal_oldest_waiting_seconds / 60)} min`
                                    : ''}
                            </Row>
                            <TaskRow
                                label="Last backup"
                                task={db.last_backup}
                            />
                            <TaskRow label="Last check" task={db.check} />
                            <TaskRow label="Last verify" task={db.verify} />
                            <Row label="Backups kept">{db.count}</Row>
                            <Row label="Repository size (1 / 2)">
                                {bytes(db.repo1_bytes)} /{' '}
                                {bytes(db.repo2_bytes)}
                            </Row>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Evidence backups (restic)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TaskRow
                                label="Last backup"
                                task={evidence.last_backup}
                            />
                            <Row label="Newest snapshot">
                                {when(evidence.newest_snapshot_at)}
                            </Row>
                            <Row label="Snapshots kept">
                                {evidence.snapshots}
                            </Row>
                            <TaskRow label="Last check" task={evidence.check} />
                            <TaskRow
                                label="Last data check (5%)"
                                task={evidence.check_data}
                            />
                            <Row label="Repository size (1 / 2)">
                                {bytes(evidence.repo1_bytes)} /{' '}
                                {bytes(evidence.repo2_bytes)}
                            </Row>
                            <Row label="Last restore drill">
                                {backups.drill ? (
                                    <>
                                        <State
                                            ok={backups.drill.result === 'pass'}
                                            label={backups.drill.result}
                                        />{' '}
                                        {when(backups.drill.at)} ·{' '}
                                        {backups.drill.seconds}s
                                        {backups.drill.within_rto
                                            ? ', within RTO'
                                            : ', OVER RTO'}
                                    </>
                                ) : (
                                    'Never'
                                )}
                            </Row>
                        </CardContent>
                    </Card>

                    <Card data-test="health-storage">
                        <CardHeader>
                            <CardTitle>Storage</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {storage.disks.map((d) => (
                                <Row key={d.name} label={d.name}>
                                    {d.used_percent === null ? (
                                        'Not mounted'
                                    ) : (
                                        <>
                                            <span
                                                className={
                                                    d.used_percent > 90
                                                        ? 'font-medium text-destructive'
                                                        : d.used_percent > 80
                                                          ? 'font-medium text-amber-600'
                                                          : ''
                                                }
                                            >
                                                {d.used_percent}% used
                                            </span>{' '}
                                            · {bytes(d.free)} free of{' '}
                                            {bytes(d.total)}
                                        </>
                                    )}
                                </Row>
                            ))}
                            {backups.repository_disk && (
                                <Row label="Backup repository disk">
                                    {backups.repository_disk.used_percent}% used
                                    · {bytes(backups.repository_disk.free)} free
                                </Row>
                            )}
                            <Row label="Database size">
                                {bytes(storage.database_bytes)}
                            </Row>
                            <details className="mt-2 text-sm">
                                <summary className="cursor-pointer text-muted-foreground">
                                    Largest tables
                                </summary>
                                {storage.largest_tables.map((t) => (
                                    <Row key={t.name} label={t.name}>
                                        {bytes(t.bytes)}
                                    </Row>
                                ))}
                            </details>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Audit integrity and keys</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Row label="Last chain verification">
                                {audit.last_verify ? (
                                    <>
                                        <State ok={audit.last_verify.ok} />{' '}
                                        {when(audit.last_verify.at)}
                                    </>
                                ) : (
                                    'Not run yet'
                                )}
                            </Row>
                            <details className="mt-2 text-sm">
                                <summary className="cursor-pointer text-muted-foreground">
                                    Current anchors ({audit.anchors.length})
                                </summary>
                                {audit.anchors.map((a) => (
                                    <Row key={a.table} label={a.table}>
                                        <span className="font-mono text-xs">
                                            #{a.seq} {a.hash.slice(0, 16)}…
                                        </span>
                                    </Row>
                                ))}
                            </details>
                            {report.keys.map((k) => (
                                <Row
                                    key={k.name}
                                    label={`${k.name} fingerprint`}
                                >
                                    <span className="font-mono text-xs break-all">
                                        {k.fingerprint}
                                    </span>
                                </Row>
                            ))}
                            <Row label="TLS certificate">
                                {report.certificate.expires_at
                                    ? `Expires ${formatDateTime(report.certificate.expires_at)} (${report.certificate.days_left} days)`
                                    : report.certificate.configured
                                      ? 'Configured but unreadable'
                                      : 'Not configured (Phase 9)'}
                            </Row>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

SystemHealth.layout = {
    breadcrumbs: [
        { title: 'System health', href: SystemHealthController.index() },
    ],
};
