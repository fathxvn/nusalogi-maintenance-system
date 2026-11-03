import { Link, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { CustomerDeleteAction } from '@/components/customer-delete-action';
import { Button, buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type Customer = {
    id: number;
    name: string;
    pic_name: string | null;
    phone: string | null;
    email: string | null;
    sites_count: number;
    links: { show: string; edit: string; destroy: string };
};

type Props = {
    customers: Paginated<Customer>;
    filters: {
        search: string;
    };
    links: {
        index: string;
        create: string;
    };
};

export default function CustomerIndex({
    customers,
    filters,
    links,
}: Props) {
    const form = useForm({
        search: filters.search,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        form.get(links.index, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <>
            {/* Customer Summary */}
            <section className="panel">
                <h2 className="section-title">
                    Daftar Customer
                </h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Total {customers.total} customer terdaftar
                </p>
            </section>

            <div className="flex justify-end">
                <Link href={links.create} className={cn(buttonVariants(), 'h-10 rounded-md px-4 hover:bg-primary-hover')}>
                    <Plus aria-hidden="true" />
                    Tambah Customer
                </Link>
            </div>

            {/* Customer Search */}
            <form
                onSubmit={submit}
                className="flex flex-wrap items-end gap-3"
                aria-label="Pencarian customer"
            >
                <div className="flex min-w-52 flex-1 flex-col gap-2">
                    <label
                        htmlFor="customer-search"
                        className="text-xs font-medium"
                    >
                        Cari nama customer, PIC, atau email
                    </label>

                    <input
                        id="customer-search"
                        type="search"
                        className="field-control"
                        placeholder="Cari customer..."
                        maxLength={100}
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                        aria-invalid={!!form.errors.search}
                        aria-describedby={
                            form.errors.search
                                ? 'customer-search-error'
                                : undefined
                        }
                    />

                    {form.errors.search && (
                        <p
                            id="customer-search-error"
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {form.errors.search}
                        </p>
                    )}
                </div>

                <Button
                    type="submit"
                    variant="outline"
                    className="h-10 rounded-md"
                    disabled={form.processing}
                    aria-busy={form.processing}
                >
                    <Search aria-hidden="true" />
                    {form.processing ? 'Mencari...' : 'Cari'}
                </Button>

                <Link
                    href={links.index}
                    className="inline-flex min-h-10 items-center px-2 text-sm text-muted-foreground underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-ring"
                >
                    Reset filter
                </Link>
            </form>

            {/* Customer Table */}
            <div className="table-shell">
                <table className="soft-table">
                    <thead>
                        <tr>
                            <th>Nama Customer</th>
                            <th>PIC</th>
                            <th>Telepon</th>
                            <th>Email</th>
                            <th>Jumlah Site</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        {customers.data.length > 0 ? (
                            customers.data.map((customer) => (
                                <tr key={customer.id}>
                                    <td className="font-medium">
                                        {customer.name}
                                    </td>

                                    <td>
                                        {customer.pic_name ?? '—'}
                                    </td>

                                    <td>
                                        {customer.phone ?? '—'}
                                    </td>

                                    <td>
                                        {customer.email ?? '—'}
                                    </td>

                                    <td>
                                        {customer.sites_count}
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <Link href={customer.links.show} className={cn(buttonVariants({ variant: 'ghost' }), 'h-9 px-2 text-xs')} aria-label={`Detail customer ${customer.name}`}>
                                                Detail
                                            </Link>
                                            <Link href={customer.links.edit} className={cn(buttonVariants({ variant: 'ghost' }), 'h-9 px-2 text-xs')} aria-label={`Edit customer ${customer.name}`}>
                                                Edit
                                            </Link>
                                            <CustomerDeleteAction customer={customer} action={customer.links.destroy} />
                                        </div>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="empty-state"
                                >
                                    {filters.search
                                        ? 'Tidak ada customer yang sesuai dengan pencarian.'
                                        : 'Belum ada data customer.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Customer Pagination */}
            <nav
                aria-label="Pagination customer"
                className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
            >
                <p>
                    {customers.from ?? 0}–
                    {customers.to ?? 0} dari{' '}
                    {customers.total} customer · Halaman{' '}
                    {customers.current_page} /{' '}
                    {customers.last_page}
                </p>

                <div className="flex gap-2">
                    {customers.prev_page_url ? (
                        <Link
                            preserveScroll
                            href={customers.prev_page_url}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            Sebelumnya
                        </Link>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled
                        >
                            Sebelumnya
                        </Button>
                    )}

                    {customers.next_page_url ? (
                        <Link
                            preserveScroll
                            href={customers.next_page_url}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            Berikutnya
                        </Link>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled
                        >
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
