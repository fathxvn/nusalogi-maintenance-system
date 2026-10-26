import { Link } from '@inertiajs/react';
import {
    Boxes,
    Building2,
    ClipboardList,
    LayoutDashboard,
    PanelsTopLeft,
    Receipt,
    Users,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import type { NavigationItem } from '@/types';

const navigationIcons: Record<NavigationItem['id'], LucideIcon> = {
    dashboard: LayoutDashboard,
    'work-orders': ClipboardList,
    customers: Building2,
    assets: Boxes,
    invoices: Receipt,
    users: Users,
    preview: PanelsTopLeft,
};

type Props = {
    items: NavigationItem[];
    onNavigate?: () => void;
};

export function AppBrand() {
    return (
        <div className="flex flex-col gap-0.5">
            <p className="text-xl font-semibold tracking-[-0.04em] text-foreground">Nusalogi</p>
            <p className="text-xs tracking-[0.02em] text-muted-foreground">Maintenance System</p>
        </div>
    );
}

export function AppSidebar({ items, onNavigate }: Props) {
    const groups = [
        { label: 'Operasional', items: items.filter((item) => item.id !== 'users' && item.id !== 'preview') },
        { label: 'Sistem', items: items.filter((item) => item.id === 'users' || item.id === 'preview') },
    ];

    return (
        <nav aria-label="Navigasi utama" className="flex flex-1 flex-col gap-7 overflow-y-auto px-3 py-3">
            {groups.filter((group) => group.items.length > 0).map((group) => (
                <div key={group.label} className="flex flex-col gap-2">
                    <p className="px-3 text-[11px] font-medium tracking-[0.08em] text-muted-foreground uppercase">
                        {group.label}
                    </p>
                    <ul className="flex flex-col gap-0.5">
                        {group.items.map((item) => {
                            const Icon = navigationIcons[item.id];
                            const content = (
                                <>
                                    <Icon className="size-4 shrink-0" strokeWidth={1.7} aria-hidden="true" />
                                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                </>
                            );
                            const className = cn(
                                'flex min-h-11 items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:ring-offset-2 focus-visible:ring-offset-sidebar motion-reduce:transition-none',
                                item.active
                                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                    : 'text-sidebar-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                            );

                            return (
                                <li key={item.id}>
                                    {!item.href ? (
                                        <div
                                            aria-disabled="true"
                                            title={`${item.label} belum tersedia`}
                                            className="flex min-h-11 items-center gap-2.5 rounded-md px-3 py-2 text-muted-foreground"
                                        >
                                            <Icon className="size-4 shrink-0" strokeWidth={1.7} aria-hidden="true" />
                                            <span className="min-w-0 flex-1 truncate text-sm">{item.label}</span>
                                            <span className="shrink-0 text-[11px]">Belum tersedia</span>
                                        </div>
                                    ) : item.inertia ? (
                                        <Link
                                            href={item.href}
                                            aria-current={item.active ? 'page' : undefined}
                                            className={className}
                                            onClick={onNavigate}
                                        >
                                            {content}
                                        </Link>
                                    ) : (
                                        <a
                                            href={item.href}
                                            aria-current={item.active ? 'page' : undefined}
                                            className={className}
                                            onClick={onNavigate}
                                        >
                                            {content}
                                        </a>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
