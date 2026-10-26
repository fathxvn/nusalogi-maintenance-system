import { ChevronsUpDown, LogOut, UserRound } from 'lucide-react';

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

    return (
        <DropdownMenu>
            <DropdownMenuTrigger render={<Button variant="ghost" className="h-11 gap-2 px-2" />} aria-label={`Menu pengguna ${user.name}`}>
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full border bg-muted">
                    <UserRound className="size-4" aria-hidden="true" />
                </span>
                <span className="hidden max-w-40 truncate text-sm sm:block">{user.name}</span>
                <ChevronsUpDown className="size-3.5 text-muted-foreground" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuGroup>
                    <DropdownMenuLabel className="flex flex-col gap-1 py-2">
                        <span className="truncate text-sm font-medium text-foreground">{user.name}</span>
                        <span className="text-xs font-normal">{roleLabel}</span>
                    </DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem nativeButton render={<button type="submit" form="nusalogi-logout-form" />} variant="destructive" className="min-h-11">
                    <LogOut aria-hidden="true" />
                    Keluar
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
