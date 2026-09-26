import { UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A person's photo thumbnail (streamed through the authorised route), or a
 * placeholder.
 */
export default function PersonAvatar({
    src,
    name,
    className,
}: {
    src: string | null;
    name: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted text-muted-foreground',
                className,
            )}
        >
            {src ? (
                <img
                    src={src}
                    alt={`Photo of ${name}`}
                    className="size-full object-cover"
                    loading="lazy"
                />
            ) : (
                <UserRound className="size-1/2" aria-hidden />
            )}
        </span>
    );
}
