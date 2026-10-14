import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { dashboard } from '@/routes';

/**
 * On a national user's state or zone view (Phase 11A.2): back to the
 * national dashboard (lens=off, so a lens does not bring them back here).
 */
export function BackToNational() {
    return (
        <Link
            href={dashboard({ query: { lens: 'off' } })}
            className="inline-flex items-center gap-1 pt-1 text-sm underline"
            data-test="back-to-national"
        >
            <ArrowLeft className="size-3.5" aria-hidden />
            All of Nigeria
        </Link>
    );
}
