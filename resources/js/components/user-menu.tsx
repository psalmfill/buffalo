import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUser } from '@/hooks/use-auth';

/** Header user menu: who is signed in, settings and sign out. */
export function UserMenu() {
    const user = useCurrentUser();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="h-9 gap-2 px-1.5"
                    aria-label="User menu"
                    data-test="user-menu-button"
                >
                    <UserInfo user={user} compact />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="min-w-60 rounded-lg" align="end">
                <UserMenuContent user={user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
