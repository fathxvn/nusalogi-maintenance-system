import { useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { UserSummary } from '@/types';

type Props = {
    user: UserSummary;
};

export default function MigrationTest({ user }: Props) {
    const [clickCount, setClickCount] = useState(0);

    return (
        <div className="flex w-full max-w-4xl flex-col gap-6">
            <p className="text-sm leading-6 text-muted-foreground">Selamat datang, {user.name}.</p>

            <section aria-labelledby="interaction-title">
                <Card className="gap-0 rounded-lg py-0 ring-0">
                    <CardHeader className="gap-2 px-6 pt-6 sm:px-7 sm:pt-7">
                        <CardTitle id="interaction-title" className="text-[17px] leading-6 font-semibold">
                            Kontrol interaksi
                        </CardTitle>
                        <CardDescription className="max-w-lg text-sm leading-6">
                            Periksa respons tombol dan perubahan status sebelum melanjutkan ke ruang kerja.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6 px-6 py-6 sm:px-7">
                        <p role="status" aria-live="polite" aria-atomic="true" className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="text-[32px] leading-none font-medium tracking-tight text-foreground tabular-nums">{clickCount}</span>
                            <span className="text-sm text-muted-foreground">kali tombol ditekan</span>
                        </p>
                        <div className="flex flex-wrap gap-2.5">
                            <Button
                                type="button"
                                onClick={() => setClickCount((count) => count + 1)}
                                className="h-10 rounded-md px-4 hover:bg-primary-hover"
                            >
                                Uji tombol
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setClickCount(0)}
                                disabled={clickCount === 0}
                                className="h-10 rounded-md px-4"
                            >
                                Reset hitungan
                            </Button>
                            <Button type="button" variant="secondary" disabled className="h-10 rounded-md px-4">
                                Tidak tersedia
                            </Button>
                        </div>
                    </CardContent>
                    <CardFooter className="border-0 bg-transparent px-6 pt-0 pb-6 sm:px-7 sm:pb-7">
                        <p className="text-xs leading-5 text-muted-foreground">
                            Hitungan hanya berlaku pada sesi halaman ini dan tidak mengubah data operasional.
                        </p>
                    </CardFooter>
                </Card>
            </section>

            <p className="max-w-xl text-[13px] leading-6 text-muted-foreground">
                Halaman ini digunakan untuk meninjau tampilan ruang kerja. Modul yang belum tersedia ditandai pada navigasi.
            </p>
        </div>
    );
}

MigrationTest.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Pratinjau ruang kerja"
        description="Tinjau navigasi dan kontrol yang digunakan dalam operasional Nusalogi."
    >
        {page}
    </AuthenticatedLayout>
);
