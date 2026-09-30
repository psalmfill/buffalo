import { Link, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavSection } from '@/config/navigation';
import { useCurrentUrl } from '@/hooks/use-current-url';

export function NavMain({ sections }: { sections: NavSection[] }) {
    const { isCurrentUrl } = useCurrentUrl();
    const badges = usePage().props.badges;

    return (
        <>
            {sections.map((section) => (
                <SidebarGroup key={section.title} className="px-2 py-0">
                    <SidebarGroupLabel>{section.title}</SidebarGroupLabel>
                    <SidebarMenu>
                        {section.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={
                                        !item.external &&
                                        isCurrentUrl(item.href)
                                    }
                                    tooltip={{ children: item.title }}
                                >
                                    {item.external ? (
                                        <a
                                            href={
                                                typeof item.href === 'string'
                                                    ? item.href
                                                    : item.href.url
                                            }
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <item.icon />
                                            <span>{item.title}</span>
                                            <ExternalLink className="ml-auto size-3 opacity-60" />
                                        </a>
                                    ) : (
                                        <Link href={item.href} prefetch>
                                            <item.icon />
                                            <span>{item.title}</span>
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                                {item.badge &&
                                    (badges?.[item.badge] ?? 0) > 0 && (
                                        <SidebarMenuBadge
                                            aria-label={`${badges[item.badge]} awaiting your review`}
                                        >
                                            {badges[item.badge]}
                                        </SidebarMenuBadge>
                                    )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
