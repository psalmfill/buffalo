import { Link } from '@inertiajs/react';
import ReferenceDataController from '@/actions/App/Http/Controllers/ReferenceData/ReferenceDataController';
import { cn } from '@/lib/utils';
import type { ReferenceTableInfo } from '@/types/reference-data';

/** One tab per lookup table. */
export default function TableTabs({
    tables,
    current,
}: {
    tables: ReferenceTableInfo[];
    current: string;
}) {
    return (
        <nav
            className="flex gap-1 overflow-x-auto border-b"
            aria-label="Reference data tables"
        >
            {tables.map((table) => (
                <Link
                    key={table.slug}
                    href={ReferenceDataController.index(table.slug)}
                    className={cn(
                        '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm',
                        table.slug === current
                            ? 'border-primary font-medium'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                    aria-current={table.slug === current ? 'page' : undefined}
                >
                    {table.label}
                </Link>
            ))}
        </nav>
    );
}
