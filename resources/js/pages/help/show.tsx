import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect } from 'react';
import HelpController from '@/actions/App/Http/Controllers/HelpController';
import type { ManualEntry, TocEntry } from '@/types/help';

type Props = {
    manual: Pick<ManualEntry, 'kind' | 'slug' | 'title' | 'summary'> & {
        role: string | null;
    };
    /** Rendered and sanitised on the server: no raw HTML from the source, no images, no external links. */
    html: string;
    toc: TocEntry[];
};

/** One manual (Phase 9B.4). */
export default function HelpShow({ manual, html, toc }: Props) {
    // Contextual help links land on a section: scroll to it once rendered.
    useEffect(() => {
        const id = window.location.hash.slice(1);
        if (id) {
            document.getElementById(id)?.scrollIntoView();
        }
    }, [html]);

    return (
        <>
            <Head title={manual.title} />
            <div className="flex gap-8 p-4">
                <article className="max-w-3xl min-w-0 flex-1 space-y-4">
                    <Link
                        href={HelpController.index()}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" /> All help
                    </Link>
                    <h1 className="text-2xl font-semibold">{manual.title}</h1>
                    {manual.summary && (
                        <p className="text-muted-foreground">
                            {manual.summary}
                        </p>
                    )}
                    <div
                        className="manual-content"
                        data-test="manual-content"
                        // Server-rendered Markdown, sanitised (MarkdownRenderer).
                        dangerouslySetInnerHTML={{ __html: html }}
                    />
                </article>

                {toc.length > 1 && (
                    <nav className="sticky top-4 hidden w-60 shrink-0 self-start text-sm lg:block">
                        <div className="mb-2 font-medium">On this page</div>
                        <ul className="space-y-1">
                            {toc.map((entry) => (
                                <li
                                    key={entry.id}
                                    className={entry.level === 3 ? 'pl-3' : ''}
                                >
                                    <a
                                        href={`#${entry.id}`}
                                        className="text-muted-foreground hover:text-foreground"
                                    >
                                        {entry.text}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}
            </div>
        </>
    );
}

HelpShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Help', href: HelpController.index() },
        {
            title: props.manual.title,
            href: HelpController.show({
                kind: props.manual.kind,
                slug: props.manual.slug,
            }),
        },
    ],
});
