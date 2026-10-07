import { Link } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import HelpController from '@/actions/App/Http/Controllers/HelpController';

type Props = {
    /** Manual kind and slug, e.g. "guides/reporting-incidents". */
    topic: `${'start' | 'roles' | 'guides'}/${string}`;
    /** Heading id inside the manual. */
    section?: string;
    label?: string;
};

/**
 * Contextual help (Phase 9B.4): a "?" link to the relevant manual section.
 */
export default function HelpLink({ topic, section, label = 'Help' }: Props) {
    const [kind, slug] = topic.split('/') as [string, string];
    const href =
        HelpController.show({ kind, slug }).url +
        (section ? `#${section}` : '');

    return (
        <Link
            href={href}
            className="inline-flex size-7 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
            title={label}
            aria-label={label}
            data-test="help-link"
        >
            <CircleHelp className="size-4" />
        </Link>
    );
}
