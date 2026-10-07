import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/time';
import { UAT_FEEDBACK } from '@/lib/uat';

type Entry = {
    ulid: string;
    role: string;
    page_url: string;
    text: string;
    user: string;
    created_at: string;
};

/** UAT feedback (Phase 9B.5), super admins: the notes and a CSV export. */
export default function UatFeedback({ feedback }: { feedback: Entry[] }) {
    return (
        <>
            <Head title="UAT feedback" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="UAT feedback"
                        description={`${feedback.length} note(s) from testers, newest first.`}
                    />
                    <Button variant="outline" asChild>
                        <a href={UAT_FEEDBACK.export}>
                            <Download /> Export CSV
                        </a>
                    </Button>
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">When</th>
                                <th className="px-3 py-2 font-medium">
                                    Tester
                                </th>
                                <th className="px-3 py-2 font-medium">Page</th>
                                <th className="px-3 py-2 font-medium">
                                    Feedback
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y align-top">
                            {feedback.map((entry) => (
                                <tr key={entry.ulid}>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {formatDateTime(entry.created_at)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {entry.user}
                                        <div className="text-xs text-muted-foreground">
                                            {entry.role}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {entry.page_url}
                                    </td>
                                    <td className="px-3 py-2 whitespace-pre-line">
                                        {entry.text}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

UatFeedback.layout = {
    breadcrumbs: [{ title: 'UAT feedback', href: UAT_FEEDBACK.index }],
};
