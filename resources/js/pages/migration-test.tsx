import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { UserSummary } from '@/types';

type Props = {
    user: UserSummary;
};

export default function MigrationTest({ user }: Props) {
    const [clickCount, setClickCount] = useState(0);

    return (
        <main className="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 p-4 sm:p-8">
            <header className="flex flex-col gap-2">
                <p className="text-sm text-muted-foreground">
                    Nusalogi Maintenance System
                </p>
                <h1 className="text-2xl font-semibold">
                    React + Inertia Migration
                </h1>
                <p className="text-muted-foreground">
                    Selamat datang, {user.name}.
                </p>
            </header>

            <Card>
                <CardHeader>
                    <CardTitle>Tampilan Komponen</CardTitle>
                    <CardDescription>
                        Coba tombol untuk melihat perubahan tampilan secara langsung.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" onClick={() => setClickCount((count) => count + 1)}>
                            Uji Tombol
                        </Button>
                        <Button type="button" variant="outline" onClick={() => setClickCount(0)} disabled={clickCount === 0}>
                            Reset Hitungan
                        </Button>
                        <Button type="button" variant="secondary" disabled>
                            Tidak Tersedia
                        </Button>
                    </div>
                    <p role="status" aria-live="polite" aria-atomic="true" className="text-sm text-muted-foreground">
                        Tombol ditekan {clickCount} kali.
                    </p>
                </CardContent>
                <CardFooter>
                    <p className="text-sm text-muted-foreground">
                        Perubahan hitungan hanya berlaku pada halaman ini.
                    </p>
                </CardFooter>
            </Card>

            <footer>
                <a href="/dashboard" className="text-sm underline underline-offset-4 hover:text-muted-foreground">
                    Kembali ke Dashboard
                </a>
            </footer>
        </main>
    );
}
