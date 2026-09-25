import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import type { PlaceholderCardData } from '@/types/dashboard';

/**
 * A dashboard card for data a later phase delivers. Until then it shows
 * "Available from Phase N"; the phase that delivers the data passes its
 * content as children.
 */
export default function PlaceholderCard({
    card,
    children,
}: {
    card: PlaceholderCardData;
    children?: ReactNode;
}) {
    return (
        <div
            className="flex min-h-32 flex-col rounded-xl border bg-card p-4"
            data-test={`placeholder-${card.key}`}
        >
            <div className="text-sm font-medium">{card.title}</div>
            <div className="text-xs text-muted-foreground">{card.scope}</div>
            <div className="mt-auto pt-4">
                {children ?? (
                    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Clock className="size-4" />
                        Available from Phase {card.availableFromPhase}
                    </div>
                )}
            </div>
        </div>
    );
}

export function PlaceholderCardGrid({
    cards,
}: {
    cards: PlaceholderCardData[];
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {cards.map((card) => (
                <PlaceholderCard key={card.key} card={card} />
            ))}
        </div>
    );
}
