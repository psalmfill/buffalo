import { router } from '@inertiajs/react';
import { FileDown, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import ListExportController from '@/actions/App/Http/Controllers/Exports/ListExportController';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/hooks/use-auth';
import { postJson } from '@/lib/http';

type FilterValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | FilterValue[]
    | { [key: string]: FilterValue };

type Preview = {
    list: string;
    label: string;
    rows: number;
    sensitive: boolean;
    columns: { key: string; label: string }[];
    defaults: string[];
    formats: {
        value: 'xlsx' | 'csv' | 'pdf';
        limit: number;
        over_limit: boolean;
    }[];
    step_up: boolean;
    reason_min: number | null;
    sensitive_left: number | null;
};

const FORMAT_LABELS = { xlsx: 'Excel (XLSX)', csv: 'CSV', pdf: 'PDF table' };

/** Empty filter values are dropped, as the page drops them. */
function clean(
    filters: Record<string, FilterValue>,
): Record<string, FilterValue> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, v]) =>
                v !== '' &&
                v !== null &&
                v !== undefined &&
                !(Array.isArray(v) && v.length === 0),
        ),
    );
}

/**
 * The Export button of a list page (Phase 11E). It exports exactly the
 * rows the page lists for its current filters (never everything regardless
 * of filters), through the requester's own access, as XLSX, CSV or a PDF
 * table with the chosen columns. The file is generated as a report and
 * downloaded from "My reports". Large exports, sensitive lists and the
 * users list ask for the authenticator code; sensitive lists also need a
 * stated reason, which is recorded and sent to the Director General.
 */
export default function ExportListButton({
    list,
    filters = {},
    sensitive = false,
    superAdminList = false,
    label = 'Export',
}: {
    /** The exporter key, e.g. "arrests". */
    list: string;
    /** The page's current filters. */
    filters?: Record<string, FilterValue>;
    sensitive?: boolean;
    /** The users list: offered to super admins only. */
    superAdminList?: boolean;
    label?: string;
}) {
    const { can } = useAuth();
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [format, setFormat] = useState<'xlsx' | 'csv' | 'pdf'>('xlsx');
    const [columns, setColumns] = useState<string[]>([]);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const allowed = superAdminList
        ? can('exports.lists') && can('users.manage')
        : can(sensitive ? 'exports.sensitive' : 'exports.lists');

    if (!allowed) {
        return null;
    }

    const values = clean(filters);

    const start = () => {
        setOpen(true);
        setLoading(true);
        setErrors({});
        postJson<Preview>(ListExportController.preview.url(list), {
            filters: values,
        })
            .then((p) => {
                setPreview(p);
                setColumns(p.defaults);
                setFormat(
                    p.formats.find((f) => !f.over_limit)?.value ?? 'xlsx',
                );
            })
            .catch(() =>
                setErrors({
                    filters:
                        'This list could not be prepared for export. Try again.',
                }),
            )
            .finally(() => setLoading(false));
    };

    const submit = () => {
        router.post(
            ListExportController.store.url(),
            { list, format, filters: values, columns, reason } as never,
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (e) => setErrors(e),
                onSuccess: () => setOpen(false),
            },
        );
    };

    const chosen = preview?.formats.find((f) => f.value === format);
    const reasonShort =
        preview?.reason_min !== null &&
        preview?.reason_min !== undefined &&
        reason.trim().length < preview.reason_min;
    const blocked =
        !preview ||
        chosen?.over_limit === true ||
        columns.length === 0 ||
        reasonShort ||
        preview.sensitive_left === 0;

    return (
        <>
            <Button
                variant="outline"
                onClick={start}
                data-test={`export-${list}`}
            >
                <FileDown /> {label}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            Export {preview?.label ?? 'list'}
                        </DialogTitle>
                        <DialogDescription>
                            The rows this page lists for its current filters,
                            through your own access. The file is marked with its
                            classification and your name, kept for a limited
                            time, and only you can download it.
                        </DialogDescription>
                    </DialogHeader>

                    {loading && (
                        <p className="flex items-center gap-2 text-sm">
                            <Spinner /> Counting rows…
                        </p>
                    )}

                    {preview && (
                        <div className="space-y-4 text-sm">
                            <p data-test="export-rows">
                                <strong>
                                    {preview.rows.toLocaleString('en-NG')}
                                </strong>{' '}
                                {preview.rows === 1 ? 'row' : 'rows'} with the
                                current filters.
                            </p>

                            {preview.sensitive && (
                                <div
                                    className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3"
                                    data-test="sensitive-notice"
                                >
                                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                                    <p>
                                        This is a sensitive list. The export
                                        needs your authenticator code and a
                                        reason, is limited to{' '}
                                        {preview.formats[0]?.limit.toLocaleString(
                                            'en-NG',
                                        )}{' '}
                                        rows and to{' '}
                                        {preview.sensitive_left ?? 0} more
                                        today, and the Director General is told
                                        at once. It never contains NIN, phone
                                        numbers, addresses, dates of birth or
                                        photos.
                                    </p>
                                </div>
                            )}
                            {!preview.sensitive && preview.step_up && (
                                <p
                                    className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3"
                                    data-test="step-up-notice"
                                >
                                    You will be asked for the code from your
                                    authenticator app, and the export is logged
                                    in your name.
                                </p>
                            )}

                            <div className="grid max-w-xs gap-1">
                                <Label htmlFor={`export-format-${list}`}>
                                    Format
                                </Label>
                                <NativeSelect
                                    id={`export-format-${list}`}
                                    value={format}
                                    onChange={(e) =>
                                        setFormat(
                                            e.target.value as typeof format,
                                        )
                                    }
                                >
                                    {preview.formats.map((f) => (
                                        <option
                                            key={f.value}
                                            value={f.value}
                                            disabled={f.over_limit}
                                        >
                                            {FORMAT_LABELS[f.value]}
                                            {f.over_limit
                                                ? ` (up to ${f.limit.toLocaleString('en-NG')} rows)`
                                                : ''}
                                        </option>
                                    ))}
                                </NativeSelect>
                                {chosen?.over_limit && (
                                    <p className="text-destructive">
                                        Too many rows for this format. Narrow
                                        the filters on the page.
                                    </p>
                                )}
                            </div>

                            <fieldset className="space-y-2">
                                <legend className="font-medium">Columns</legend>
                                <div className="grid max-h-56 gap-1.5 overflow-y-auto sm:grid-cols-2">
                                    {preview.columns.map((c) => (
                                        <label
                                            key={c.key}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                checked={columns.includes(
                                                    c.key,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setColumns((list) =>
                                                        checked === true
                                                            ? [...list, c.key]
                                                            : list.filter(
                                                                  (k) =>
                                                                      k !==
                                                                      c.key,
                                                              ),
                                                    )
                                                }
                                            />
                                            {c.label}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.columns} />
                            </fieldset>

                            {preview.reason_min !== null && (
                                <div className="grid gap-1">
                                    <Label htmlFor={`export-reason-${list}`}>
                                        Reason for this export
                                    </Label>
                                    <Textarea
                                        id={`export-reason-${list}`}
                                        value={reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        rows={3}
                                        placeholder={`At least ${preview.reason_min} characters, e.g. the operation or request it supports`}
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                            )}
                        </div>
                    )}

                    <InputError
                        message={
                            errors.filters ??
                            errors.quota ??
                            errors.format ??
                            errors.list
                        }
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={blocked || busy}
                            onClick={submit}
                            data-test="export-submit"
                        >
                            {busy && <Spinner />} Export
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
