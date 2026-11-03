import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { Paginated } from '@/types';

type Customer = {
    id: number;
    name: string;
    pic_name: string | null;
    phone: string | null;
    email: string | null;
    sites_count: number;
};

type Props = {
    customers: Paginated<Customer>;
};

export default function CustomerIndex({ customers }: Props) {
    return (
        <>
            <section className="panel">
                <h2 className="section-title">
                    Daftar Customer
                </h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Total {customers.total} customer terdaftar
                </p>
            </section>

            <div className="table-shell">
                <table className="soft-table">
                    <thead>
                        <tr>
                            <th>Nama Customer</th>
                            <th>PIC</th>
                            <th>Telepon</th>
                            <th>Email</th>
                            <th>Jumlah Site</th>
                        </tr>
                    </thead>

                    <tbody>
                        {customers.data.length > 0 ? (
                            customers.data.map((customer) => (
                                <tr key={customer.id}>
                                    <td className="font-medium">{customer.name}</td>
                                    <td>{customer.pic_name ?? '—'}</td>
                                    <td>{customer.phone ?? '—'}</td>
                                    <td>{customer.email ?? '—'}</td>
                                    <td>{customer.sites_count}</td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={5} className="empty-state">
                                    Belum ada data customer.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <nav
                aria-label="Pagination customer"
                className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
            >
                <p>
                    {customers.from ?? 0}–{customers.to ?? 0} dari {customers.total} customer · Halaman{' '}
                    {customers.current_page} / {customers.last_page}
                </p>

                <div className="flex gap-2">
                    {customers.prev_page_url ? (
                        <Link
                            preserveScroll
                            href={customers.prev_page_url}
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        >
                            Sebelumnya
                        </Link>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Sebelumnya
                        </Button>
                    )}

                    {customers.next_page_url ? (
                        <Link
                            preserveScroll
                            href={customers.next_page_url}
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        >
                            Berikutnya
                        </Link>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Berikutnya
                        </Button>
                    )}
                </div>
            </nav>
        </>
    );
}

CustomerIndex.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Manajemen Customer"
        description="Kelola data pelanggan Nusalogi Integrasi Sistem."
    >
        {page}
    </AuthenticatedLayout>
);
