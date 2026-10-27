import { Link, usePage } from '@inertiajs/react';
import StateController from '@/actions/App/Http/Controllers/LocalGovernment/StateController';
import { cn } from '@/lib/utils';

/**
 * A state's name, linked to its state page for users who may open it
 * (Phase 11F); plain text otherwise. The list of openable states is shared
 * by the server; the page itself is authorised there too.
 */
export default function StateLink({
    state,
    suffix = '',
    className,
}: {
    state: { ulid: string; name: string };
    /** Appended to the name, e.g. " State". */
    suffix?: string;
    className?: string;
}) {
    const { stateLinks } = usePage().props;
    const label = `${state.name}${suffix}`;

    if (!stateLinks?.includes(state.ulid)) {
        return <span className={className}>{label}</span>;
    }

    return (
        <Link
            href={StateController.show(state.ulid)}
            className={cn('hover:underline', className)}
        >
            {label}
        </Link>
    );
}
