import { Head, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { AppBrand, AppSidebar } from '@/components/app-sidebar';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { UserMenu } from '@/components/user-menu';
import type { SharedProps } from '@/types';

type Props = {
    title: string;
    description?: string;
    children: ReactNode;
};

export default function AuthenticatedLayout({ title, description, children }: Props) {
    const { auth, navigation, flash } = usePage<SharedProps>().props;
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        const desktop = window.matchMedia('(min-width: 64rem)');
        const closeOnDesktop = () => {
            if (desktop.matches) {
                setMobileOpen(false);
            }
        };

        desktop.addEventListener('change', closeOnDesktop);

        return () => desktop.removeEventListener('change', closeOnDesktop);
    }, []);

    if (!auth.user) {
        return null;
    }

    return (
        <div className="min-h-dvh bg-background">
            <Head title={`${title} - Nusalogi`} />
            <a
                href="#main-content"
                className="sr-only z-100 rounded-md bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                Lewati ke konten utama
            </a>

            <aside aria-label="Sidebar" className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r border-sidebar-border bg-sidebar lg:flex">
                <div className="px-6 pt-7 pb-8">
                    <AppBrand />
                </div>
                <AppSidebar items={navigation} />
                <div className="px-6 pt-4 pb-6 text-xs leading-5 text-muted-foreground">
                    Sistem operasional internal
                </div>
            </aside>

            <div className="min-w-0 lg:pl-64">
                <header className="sticky top-0 z-30 bg-background">
                    <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-5 sm:px-8 lg:px-10">
                        <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                            <SheetTrigger
                                render={<Button variant="ghost" size="icon" className="-ml-2 size-11 shrink-0 lg:hidden" />}
                                aria-label="Buka navigasi"
                            >
                                <Menu aria-hidden="true" />
                            </SheetTrigger>
                            <SheetContent
                                side="left"
                                className="gap-0 border-0 bg-sidebar p-0 shadow-none data-[side=left]:w-[min(20rem,90vw)] data-[side=left]:border-0"
                            >
                                <SheetHeader className="px-6 pt-7 pb-8 text-left">
                                    <SheetTitle className="text-xl font-semibold tracking-[-0.04em]">Nusalogi</SheetTitle>
                                    <SheetDescription className="text-xs tracking-[0.02em]">Maintenance System</SheetDescription>
                                </SheetHeader>
                                <AppSidebar items={navigation} onNavigate={() => setMobileOpen(false)} />
                            </SheetContent>
                        </Sheet>
                        <nav aria-label="Jejak navigasi" className="min-w-0 flex-1 text-[13px]">
                            <ol className="flex items-center gap-2.5">
                                <li className="hidden shrink-0 text-muted-foreground sm:block">Ruang kerja</li>
                                <li aria-hidden="true" className="hidden text-muted-foreground sm:block">/</li>
                                <li aria-current="page" className="truncate text-foreground">{title}</li>
                            </ol>
                        </nav>
                        <UserMenu user={auth.user} />
                    </div>
                </header>

                <main
                    id="main-content"
                    tabIndex={-1}
                    className="mx-auto flex w-full max-w-7xl flex-col gap-8 px-5 pt-6 pb-10 outline-none sm:px-8 sm:pt-8 lg:px-10"
                >
                    <div className="flex max-w-3xl flex-col gap-2">
                        <h1 className="text-2xl leading-tight font-semibold tracking-[-0.025em] sm:text-[28px]">{title}</h1>
                        {description && <p className="text-[15px] leading-6 text-muted-foreground">{description}</p>}
                    </div>
                    {flash.success && <p role="status" className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{flash.success}</p>}
                    {children}
                </main>
            </div>
        </div>
    );
}
