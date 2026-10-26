import { ChevronDown, LogOut } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AuthenticatedUser, UserRole } from '@/types';

const roleLabels: Record<UserRole, string> = {
    admin: 'Administrator',
    koordinator: 'Koordinator',
    teknisi: 'Teknisi',
};

export function UserMenu({ user }: { user: AuthenticatedUser }) {
    const roleLabel = user.role ? roleLabels[user.role] : 'Pengguna internal';
    const initials = user.name.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toLocaleUpperCase() || 'N';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={<Button variant="ghost" className="h-11 gap-2.5 rounded-md px-1.5" />}
                aria-label={`Menu pengguna ${user.name}`}
            >
                <span
                    aria-hidden="true"
                    className="flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary text-[11px] font-medium text-secondary-foreground"
                >
                    {initials}
                </span>
                <span className="hidden max-w-40 truncate text-[13px] font-medium sm:block">{user.name}</span>
                <ChevronDown className="size-3 text-muted-foreground" strokeWidth={1.7} aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60 rounded-lg shadow-sm ring-border">
                <DropdownMenuGroup>
                    <DropdownMenuLabel className="flex flex-col gap-1 px-2 py-2.5">
                        <span className="truncate text-sm font-medium text-foreground">{user.name}</span>
                        <span className="text-xs font-normal">{roleLabel}</span>
                    </DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    nativeButton
                    render={<button type="submit" form="nusalogi-logout-form" />}
                    variant="destructive"
                    className="min-h-11 rounded-md px-2"
                >
                    <LogOut strokeWidth={1.7} aria-hidden="true" />
                    Keluar
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
