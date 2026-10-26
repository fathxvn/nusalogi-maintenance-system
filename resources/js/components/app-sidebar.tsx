import { Link } from '@inertiajs/react';
import {
    Boxes,
    Building2,
    ClipboardList,
    LayoutDashboard,
    PanelsTopLeft,
    Receipt,
    Users,
    Wrench,
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
        <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground">
                <Wrench className="size-5" aria-hidden="true" />
            </span>
            <div>
                <p className="text-base font-semibold tracking-tight">Nusalogi</p>
                <p className="text-xs text-muted-foreground">Maintenance System</p>
            </div>
        </div>
    );
}

export function AppSidebar({ items, onNavigate }: Props) {
    return (
        <nav aria-label="Navigasi utama" className="flex-1 overflow-y-auto p-3">
            <p className="px-3 pb-3 text-xs font-medium tracking-wide text-muted-foreground">RUANG KERJA</p>
            <ul className="flex flex-col gap-1">
                {items.map((item) => {
                    const Icon = navigationIcons[item.id];
                    const content = (
                        <>
                            <Icon className="size-4 shrink-0" aria-hidden="true" />
                            <span className="min-w-0 flex-1">{item.label}</span>
                        </>
                    );
                    const className = cn(
                        'flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                        item.active
                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent',
                    );

                    return (
                        <li key={item.id}>
                            {!item.href ? (
                                <div aria-disabled="true" className="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-muted-foreground">
                                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="text-sm">{item.label}</span>
                                        <span className="text-xs">Belum tersedia</span>
                                    </div>
                                </div>
                            ) : item.inertia ? (
                                <Link href={item.href} aria-current={item.active ? 'page' : undefined} className={className} onClick={onNavigate}>
                                    {content}
                                </Link>
                            ) : (
                                <a href={item.href} aria-current={item.active ? 'page' : undefined} className={className} onClick={onNavigate}>
                                    {content}
                                </a>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
