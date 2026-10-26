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
    const { auth, navigation } = usePage<SharedProps>().props;
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
        <div className="min-h-dvh bg-muted/30">
            <Head title={`${title} - Nusalogi`} />
            <a href="#main-content" className="sr-only z-100 rounded-lg bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3">
                Lewati ke konten utama
            </a>

            <aside aria-label="Sidebar" className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r bg-sidebar lg:flex">
                <div className="border-b p-5">
                    <AppBrand />
                </div>
                <AppSidebar items={navigation} />
                <div className="border-t px-6 py-4 text-xs text-muted-foreground">
                    Sistem operasional internal
                </div>
            </aside>

            <div className="min-w-0 lg:pl-64">
                <header className="sticky top-0 z-30 flex min-h-18 items-center gap-3 border-b bg-background px-4 sm:px-6 lg:px-8">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger render={<Button variant="outline" size="icon" className="size-11 shrink-0 lg:hidden" />} aria-label="Buka navigasi">
                            <Menu aria-hidden="true" />
                        </SheetTrigger>
                        <SheetContent side="left" className="gap-0 p-0 data-[side=left]:w-[min(20rem,90vw)]">
                            <SheetHeader className="border-b p-5 text-left">
                                <SheetTitle>Nusalogi</SheetTitle>
                                <SheetDescription>Maintenance System</SheetDescription>
                            </SheetHeader>
                            <AppSidebar items={navigation} onNavigate={() => setMobileOpen(false)} />
                        </SheetContent>
                    </Sheet>
                    <div className="min-w-0 flex-1 py-3">
                        <p className="text-xs text-muted-foreground">Ruang kerja</p>
                        <h1 className="truncate text-base font-semibold sm:text-lg">{title}</h1>
                    </div>
                    <UserMenu user={auth.user} />
                </header>

                <main id="main-content" tabIndex={-1} className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 outline-none sm:p-6 lg:p-8">
                    {description && <p className="max-w-2xl text-sm text-muted-foreground">{description}</p>}
                    {children}
                </main>
            </div>
        </div>
    );
}
