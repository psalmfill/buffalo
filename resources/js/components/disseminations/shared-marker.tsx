import { Share2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

/** "Shared with you": a record seen only through a dissemination (Phase 7A). */
export default function SharedMarker() {
    return (
        <Badge
            variant="outline"
            className="border-cyan-600/60 text-cyan-800 dark:text-cyan-300"
        >
            <Share2 className="size-3" /> Shared with you
        </Badge>
    );
}
