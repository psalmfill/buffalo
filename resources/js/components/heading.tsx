import HelpLink from '@/components/help-link';

export default function Heading({
    title,
    description,
    variant = 'default',
    help,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /** Contextual help (Phase 9B.4): the manual and section to open. */
    help?: { topic: Parameters<typeof HelpLink>[0]['topic']; section?: string };
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 flex items-center gap-1 text-base font-medium'
                        : 'flex items-center gap-1 text-xl font-semibold tracking-tight'
                }
            >
                {title}
                {help && <HelpLink topic={help.topic} section={help.section} />}
            </h2>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
