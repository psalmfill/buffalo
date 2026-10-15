import { Link, useForm } from '@inertiajs/react';
import { CalendarCheck, Crosshair, Pencil, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import RichTextView from '@/components/rich-text/rich-text-view';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import RiskBadge from '@/components/political/risk-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { PoiDetail, PoiOptions } from '@/types/political';

const date = (value: string | null) =>
    value ? new Date(value).toLocaleString('en-NG') : '—';

function Fact({
    label,
    value,
    rich = false,
}: {
    label: string;
    value: string | null;
    /** Rich text (Phase 11B): sanitised HTML. */
    rich?: boolean;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            {rich ? (
                <dd className="text-sm">
                    <RichTextView html={value} empty="—" />
                </dd>
            ) : (
                <dd className="text-sm whitespace-pre-line">{value || '—'}</dd>
            )}
        </div>
    );
}

function StatusForm({ poi, options }: { poi: PoiDetail; options: PoiOptions }) {
    const form = useForm({
        status: poi.status.value,
        risk_level: poi.risk_level.value,
        comment: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            status: data.status === poi.status.value ? null : data.status,
            risk_level:
                data.risk_level === poi.risk_level.value
                    ? null
                    : data.risk_level,
            comment: data.comment,
        }));
        form.post(PersonOfInterestController.status(poi.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('comment'),
        });
    };

    const statuses = options.statuses.filter(
        (s) =>
            s.value !== 'cleared' ||
            poi.can.clear ||
            poi.status.value === 'cleared',
    );
    const unchanged =
        form.data.status === poi.status.value &&
        form.data.risk_level === poi.risk_level.value;

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border p-4">
            <h3 className="text-sm font-semibold">Change status or risk</h3>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="poi-status">Status</Label>
                    <NativeSelect
                        id="poi-status"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.status} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="poi-risk">Risk level</Label>
                    <NativeSelect
                        id="poi-risk"
                        value={form.data.risk_level}
                        onChange={(e) =>
                            form.setData('risk_level', e.target.value)
                        }
                    >
                        {options.riskLevels.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.risk_level} />
                </div>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="poi-comment">Comment (required)</Label>
                <Textarea
                    id="poi-comment"
                    rows={2}
                    maxLength={1000}
                    value={form.data.comment}
                    onChange={(e) => form.setData('comment', e.target.value)}
                />
                <InputError message={form.errors.comment} />
            </div>
            {form.data.status === 'cleared' &&
                poi.status.value !== 'cleared' && (
                    <p className="text-xs text-muted-foreground">
                        Clearing removes the person of interest role and sets a
                        review in 30 days to decide on archiving. The record and
                        its history are kept.
                    </p>
                )}
            <Button type="submit" disabled={form.processing || unchanged}>
                Save change
            </Button>
        </form>
    );
}

function ReviewForm({ poi }: { poi: PoiDetail }) {
    const form = useForm({ comment: '', next_review: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            comment: data.comment,
            next_review: data.next_review || null,
        }));
        form.post(PersonOfInterestController.review(poi.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border p-4">
            <h3 className="text-sm font-semibold">Mark reviewed</h3>
            <div className="grid gap-2">
                <Label htmlFor="review-comment">Comment (required)</Label>
                <Input
                    id="review-comment"
                    maxLength={1000}
                    value={form.data.comment}
                    onChange={(e) => form.setData('comment', e.target.value)}
                />
                <InputError message={form.errors.comment} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="next-review">
                    Next review (default: 12 months)
                </Label>
                <Input
                    id="next-review"
                    type="date"
                    value={form.data.next_review}
                    onChange={(e) =>
                        form.setData('next_review', e.target.value)
                    }
                />
                <InputError message={form.errors.next_review} />
            </div>
            <Button type="submit" variant="outline" disabled={form.processing}>
                <CalendarCheck /> Mark reviewed
            </Button>
        </form>
    );
}

/**
 * A person's person-of-interest record (the POI tab). Viewing it is
 * access-logged with context "poi".
 */
export default function PoiPanel({
    personUlid,
    poi,
    options,
    canDesignate,
}: {
    personUlid: string;
    poi: PoiDetail | null;
    options: PoiOptions | null;
    canDesignate: boolean;
}) {
    const [deleting, setDeleting] = useState(false);

    if (poi === null) {
        return (
            <div className="space-y-4 rounded-lg border">
                <EmptyState
                    title="Not a person of interest"
                    description="Designating a person records their offences, status and risk, and schedules reviews."
                />
                {canDesignate && (
                    <div className="flex justify-center pb-6">
                        <Button asChild>
                            <Link
                                href={PersonOfInterestController.create(
                                    personUlid,
                                )}
                            >
                                <Crosshair /> Designate as person of interest
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
            <div className="space-y-4">
                <section className="space-y-4 rounded-lg border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <RiskBadge risk={poi.risk_level} />
                            <Badge variant="outline">{poi.status.label}</Badge>
                            {poi.review_overdue && (
                                <Badge variant="destructive">
                                    Review overdue
                                </Badge>
                            )}
                        </div>
                        <div className="flex gap-2">
                            {poi.can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={PersonOfInterestController.edit(
                                            poi.ulid,
                                        )}
                                    >
                                        <Pencil /> Edit details
                                    </Link>
                                </Button>
                            )}
                            {poi.can.delete && (
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => setDeleting(true)}
                                >
                                    <Trash2 /> Delete
                                </Button>
                            )}
                        </div>
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Fact
                                label="Offence categories"
                                value={poi.offence_categories
                                    .map((o) => o.label)
                                    .join(', ')}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Fact
                                label="Known offences"
                                value={poi.known_offences}
                                rich
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Fact
                                label="Modus operandi"
                                value={poi.modus_operandi}
                                rich
                            />
                        </div>
                        <Fact
                            label="Last known location"
                            value={
                                [
                                    poi.last_known_location_description,
                                    poi.latitude !== null
                                        ? `${poi.latitude}, ${poi.longitude}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || null
                            }
                        />
                        <Fact
                            label="Last sighted"
                            value={
                                poi.last_sighted_at
                                    ? date(poi.last_sighted_at)
                                    : null
                            }
                        />
                        <Fact label="Review due" value={poi.review_due_at} />
                        <Fact
                            label="Last reviewed"
                            value={
                                poi.last_reviewed_at
                                    ? `${date(poi.last_reviewed_at)} by ${poi.last_reviewed_by ?? '—'}`
                                    : null
                            }
                        />
                    </dl>
                </section>

                <section className="space-y-3 rounded-lg border p-4">
                    <h3 className="text-sm font-semibold">
                        Status and risk history
                    </h3>
                    <ol className="space-y-3 border-l pl-4">
                        {poi.timeline.map((entry, index) => (
                            <li key={index} className="relative text-sm">
                                <span className="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-background bg-primary" />
                                <div className="font-medium">
                                    {entry.description}
                                </div>
                                {entry.comment && (
                                    <p className="whitespace-pre-line text-muted-foreground">
                                        “{entry.comment}”
                                    </p>
                                )}
                                <div className="text-xs text-muted-foreground">
                                    {date(entry.at)} · {entry.by ?? 'System'}
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>

            {poi.can.update && options && (
                <div className="space-y-4">
                    <StatusForm poi={poi} options={options} />
                    <ReviewForm poi={poi} />
                </div>
            )}

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title="Delete this person of interest record?"
                    description="The person remains; the person of interest role is removed. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={PersonOfInterestController.destroy(poi.ulid).url}
                />
            )}
        </div>
    );
}
