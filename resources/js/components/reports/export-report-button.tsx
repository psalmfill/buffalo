import { Link } from '@inertiajs/react';
import { FileDown } from 'lucide-react';
import ReportController from '@/actions/App/Http/Controllers/Reports/ReportController';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/hooks/use-auth';
import type { ReportTypeValue } from '@/types/reports';

type QueryValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | QueryValue[]
    | { [key: string]: QueryValue };

/** Nested values as PHP-style query keys (filters[types][]=…). */
function appendQuery(
    params: URLSearchParams,
    key: string,
    value: QueryValue,
): void {
    if (value === null || value === undefined || value === '') {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((v) => appendQuery(params, `${key}[]`, v));
    } else if (typeof value === 'object') {
        Object.entries(value).forEach(([k, v]) =>
            appendQuery(params, `${key}[${k}]`, v),
        );
    } else {
        params.append(key, String(value));
    }
}

/**
 * Opens /reports pre-filled for this page (Phase 7D): one incident, an
 * LGA, a coverage report or an analytics view (list exports use
 * ExportListButton since Phase 11E). Shown only with the matching permission; the server
 * authorises the report again.
 */
export default function ExportReportButton({
    type,
    parameters,
    label = 'Export',
}: {
    type: ReportTypeValue;
    parameters: Record<string, QueryValue>;
    label?: string;
}) {
    const { can } = useAuth();

    if (!can('reports.generate')) {
        return null;
    }

    const params = new URLSearchParams({ type });
    Object.entries(parameters).forEach(([k, v]) => appendQuery(params, k, v));

    return (
        <Button variant="outline" asChild>
            <Link
                href={`${ReportController.index().url}?${params.toString()}`}
                data-test="export-report"
            >
                <FileDown /> {label}
            </Link>
        </Button>
    );
}
