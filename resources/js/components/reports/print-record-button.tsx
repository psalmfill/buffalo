import { router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Reports/ReportController';
import { Button } from '@/components/ui/button';
import { useAuth } from '@/hooks/use-auth';
import type { ReportTypeValue } from '@/types/reports';

/**
 * "Print / Download" on a record's page (Phase 11E): requests the record's
 * PDF through the report pipeline (built as the requester, marked, logged,
 * downloadable by them alone from "My reports"). Person records ask for
 * the authenticator code first.
 */
export default function PrintRecordButton({
    type,
    parameter,
    ulid,
    label = 'Print / Download',
}: {
    type: ReportTypeValue;
    /** The record's parameter name, e.g. "flashpoint". */
    parameter: string;
    ulid: string;
    label?: string;
}) {
    const { can } = useAuth();
    const [busy, setBusy] = useState(false);

    if (!can('reports.generate')) {
        return null;
    }

    return (
        <Button
            variant="outline"
            disabled={busy}
            data-test="print-record"
            onClick={() =>
                router.post(
                    ReportController.store.url(),
                    {
                        type,
                        format: 'pdf',
                        parameters: { [parameter]: ulid },
                    } as never,
                    {
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    },
                )
            }
        >
            <Printer /> {label}
        </Button>
    );
}
