import { Head, Link } from '@inertiajs/react';
import { BookOpen, FileDown } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/HelpController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import type { ManualEntry } from '@/types/help';

type Props = {
    start: ManualEntry[];
    roles: ManualEntry[];
    guides: ManualEntry[];
    cards: ManualEntry[];
};

function ManualList({ manuals }: { manuals: ManualEntry[] }) {
    return (
        <ul className="grid gap-3 sm:grid-cols-2">
            {manuals.map((manual) => (
                <li key={`${manual.kind}/${manual.slug}`}>
                    <Link
                        href={HelpController.show({
                            kind: manual.kind,
                            slug: manual.slug,
                        })}
                        className="flex h-full gap-3 rounded-lg border p-3 hover:bg-muted/50"
                    >
                        <BookOpen className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div>
                            <div className="flex flex-wrap items-center gap-2 font-medium">
                                {manual.title}
                                {manual.own && <Badge>Your role</Badge>}
                            </div>
                            {manual.summary && (
                                <div className="text-sm text-muted-foreground">
                                    {manual.summary}
                                </div>
                            )}
                        </div>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** Help (Phase 9B.4): the manuals this user may read. */
export default function HelpIndex({ start, roles, guides, cards }: Props) {
    return (
        <>
            <Head title="Help" />
            <div className="max-w-5xl space-y-8 p-4">
                <Heading
                    title="Help"
                    description="How to use BUFFALO: start here, then your role's manual and the task guides."
                />

                {start.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Getting started</h2>
                        <ManualList manuals={start} />
                    </section>
                )}

                {roles.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Role manuals</h2>
                        <ManualList manuals={roles} />
                    </section>
                )}

                {guides.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">Task guides</h2>
                        <ManualList manuals={guides} />
                    </section>
                )}

                {cards.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">
                            Quick reference cards (printable, RESTRICTED)
                        </h2>
                        <ul className="flex flex-wrap gap-2">
                            {cards.map((card) => (
                                <li key={card.slug}>
                                    <a
                                        href={
                                            HelpController.card(card.role ?? '')
                                                .url
                                        }
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm hover:bg-muted/50"
                                    >
                                        <FileDown className="size-4" />
                                        {card.title}
                                        {card.own && <Badge>Your role</Badge>}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

HelpIndex.layout = {
    breadcrumbs: [{ title: 'Help', href: HelpController.index() }],
};
