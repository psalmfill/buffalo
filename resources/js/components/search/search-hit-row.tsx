import ClassificationBadge from '@/components/community-information/classification-badge';
import { SearchIcon } from '@/components/search/search-icons';
import { Badge } from '@/components/ui/badge';
import type { SearchHit } from '@/types/search';

/** A global search result: icon, title, subtitle, risk and classification. */
export default function SearchHitRow({ hit }: { hit: SearchHit }) {
    return (
        <span className="flex min-w-0 items-start gap-3">
            <SearchIcon
                type={hit.type}
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-medium">{hit.title}</span>
                    {hit.exact && (
                        <Badge variant="secondary">Exact match</Badge>
                    )}
                    {hit.badge && (
                        <Badge
                            variant={
                                hit.badge === 'critical' || hit.badge === 'high'
                                    ? 'destructive'
                                    : 'outline'
                            }
                            className="capitalize"
                        >
                            {hit.badge} risk
                        </Badge>
                    )}
                    {hit.classification &&
                        hit.classification.value !== 'restricted' && (
                            <ClassificationBadge
                                level={hit.classification.value}
                            />
                        )}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {hit.subtitle}
                </span>
            </span>
        </span>
    );
}
