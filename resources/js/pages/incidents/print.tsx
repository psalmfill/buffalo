import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import RichTextView from '@/components/rich-text/rich-text-view';
import DynamicFieldsView from '@/components/dynamic-fields/dynamic-fields-view';
import { formatDateTime } from '@/lib/time';
import type { IncidentRecord, TimelineEntry } from '@/types/incidents';

type Props = Omit<IncidentRecord, 'evidence'> & {
    evidence: {
        title: string;
        type: { label: string };
        classification: { label: string };
    }[];
    timeline: TimelineEntry[];
    printedBy: string;
    printedAt: string;
};

/**
 * A print-friendly incident (Phase 5D). The classification is printed at
 * the top and bottom of every page (fixed bars repeat on each printed
 * page), with "Printed by … on …" in the footer. Evidence is listed by
 * title only. The server logged this view as a print.
 */
export default function IncidentPrint({
    incident,
    casualties,
    persons,
    groups,
    agencies,
    properties,
    evidence,
    timeline,
    printedBy,
    printedAt,
}: Props) {
    const level = incident.classification.label.toUpperCase();

    return (
        <>
            <Head title={`Print ${incident.reference_no ?? incident.title}`} />
            <style>{`
                @page { margin: 22mm 14mm; }
                body { background: #fff; }
                .print-bar { position: fixed; left: 0; right: 0; text-align: center; font-weight: 700; letter-spacing: .2em; font-size: 11px; background: #fff; }
                .print-top { top: 0; }
                .print-bottom { bottom: 0; }
                @media screen { .print-bar { position: sticky; } }
                @media print { .no-print { display: none !important; } }
            `}</style>
            <div
                className="mx-auto max-w-4xl bg-white p-8 text-[13px] text-black"
                data-test="incident-print"
            >
                <div
                    className="print-bar print-top py-1"
                    data-test="classification-top"
                >
                    {level}
                </div>

                <div className="no-print mb-4 flex justify-end gap-2">
                    <button
                        type="button"
                        className="rounded border px-3 py-1"
                        onClick={() => window.print()}
                    >
                        Print
                    </button>
                </div>

                <header className="mb-4 border-b pb-3">
                    <p className="font-mono text-sm font-semibold">
                        {incident.reference_no ?? 'Draft — no reference yet'}
                    </p>
                    <h1 className="text-xl font-semibold">{incident.title}</h1>
                    <p>
                        {incident.type.name} · {incident.severity.label} ·{' '}
                        {incident.status.label} · {incident.lga.name} LGA,{' '}
                        {incident.state.name} State
                    </p>
                    <p>
                        Occurred {formatDateTime(incident.occurred_at)} (
                        {incident.occurred_at_precision.label.toLowerCase()})
                        {incident.community && ` · ${incident.community.name}`}
                        {incident.location_description &&
                            ` · ${incident.location_description}`}
                    </p>
                    {incident.workflow.escalation && (
                        <p>
                            Escalated to national level
                            {incident.workflow.escalation.acknowledged
                                ? `; acknowledged by ${incident.workflow.escalation.acknowledged.by}`
                                : ''}
                            .
                        </p>
                    )}
                </header>

                <Block title="What happened">
                    <RichTextView html={incident.description} />
                    {incident.type.fields.length > 0 && (
                        <DynamicFieldsView
                            fields={incident.type.fields}
                            values={incident.details}
                        />
                    )}
                    {incident.action_taken && (
                        <div className="mt-2">
                            <p className="font-medium">Action taken</p>
                            <RichTextView html={incident.action_taken} />
                        </div>
                    )}
                    {incident.workflow.resolution_summary && (
                        <div className="mt-2">
                            <p className="font-medium">Resolution</p>
                            <RichTextView
                                html={incident.workflow.resolution_summary}
                            />
                        </div>
                    )}
                </Block>

                <Block title="People">
                    {casualties.length === 0 ? (
                        <p>No casualties recorded.</p>
                    ) : (
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="border-b text-left">
                                    <th>Outcome</th>
                                    <th>Who</th>
                                    <th className="text-right">Men</th>
                                    <th className="text-right">Women</th>
                                    <th className="text-right">Boys</th>
                                    <th className="text-right">Girls</th>
                                    <th className="text-right">Unknown</th>
                                    <th className="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {casualties.map((c) => (
                                    <tr key={c.ulid} className="border-b">
                                        <td>{c.outcome.label}</td>
                                        <td>
                                            {c.group.label}
                                            {c.agency &&
                                                ` (${c.agency.short_name})`}
                                        </td>
                                        <td className="text-right">
                                            {c.adult_male}
                                        </td>
                                        <td className="text-right">
                                            {c.adult_female}
                                        </td>
                                        <td className="text-right">
                                            {c.child_male}
                                        </td>
                                        <td className="text-right">
                                            {c.child_female}
                                        </td>
                                        <td className="text-right">
                                            {c.unknown}
                                        </td>
                                        <td className="text-right font-semibold">
                                            {c.total}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    <ul className="mt-2 list-disc pl-5">
                        {persons.map((p) => (
                            <li key={p.ulid}>
                                {p.involvement.label}:{' '}
                                {p.person
                                    ? p.person.name
                                    : p.person_hidden
                                      ? 'Person on record (not visible to you)'
                                      : `Unidentified: ${p.unidentified_description}`}
                            </li>
                        ))}
                        {groups.map((g) => (
                            <li key={g.ulid}>
                                Criminal group ({g.role.label.toLowerCase()}):{' '}
                                {g.group
                                    ? g.group.name
                                    : 'a group not visible to you'}
                            </li>
                        ))}
                    </ul>
                </Block>

                <Block title="Response">
                    <ul className="list-disc pl-5">
                        {agencies.length === 0 && (
                            <li>No agencies recorded.</li>
                        )}
                        {agencies.map((a) => (
                            <li key={a.ulid}>
                                {a.role.label}: {a.agency.short_name}
                                {a.formation && `, ${a.formation.name}`}
                                {a.responded_at &&
                                    ` (responded ${formatDateTime(a.responded_at)})`}
                            </li>
                        ))}
                        {properties.map((p) => (
                            <li key={p.ulid}>
                                Property: {p.property_type.name}
                                {p.quantity !== null && ` × ${p.quantity}`}
                                {p.description && `, ${p.description}`}
                            </li>
                        ))}
                    </ul>
                </Block>

                <Block title="Evidence (titles only)">
                    {evidence.length === 0 ? (
                        <p>No evidence.</p>
                    ) : (
                        <ul className="list-disc pl-5">
                            {evidence.map((e, i) => (
                                <li key={i}>
                                    {e.title} ({e.type.label.toLowerCase()},{' '}
                                    {e.classification.label.toLowerCase()})
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>

                <Block title="Timeline">
                    <ol className="space-y-1">
                        {timeline.map((entry, i) => (
                            <li key={i}>
                                <span className="font-medium">
                                    {formatDateTime(entry.at)}
                                </span>{' '}
                                · {entry.label}
                                {entry.who && ` · ${entry.who}`}
                                {entry.body && (
                                    <RichTextView
                                        html={entry.body}
                                        format={entry.format}
                                        className="pl-4"
                                    />
                                )}
                            </li>
                        ))}
                    </ol>
                </Block>

                <div
                    className="print-bar print-bottom py-1"
                    data-test="classification-bottom"
                >
                    {level} · Printed by {printedBy} on{' '}
                    {formatDateTime(printedAt)}
                </div>
            </div>
        </>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mb-4 break-inside-avoid-page">
            <h2 className="mb-1 border-b text-sm font-semibold tracking-wide uppercase">
                {title}
            </h2>
            {children}
        </section>
    );
}
