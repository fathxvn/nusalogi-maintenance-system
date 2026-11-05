import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { CustomerDeleteAction } from '@/components/customer-delete-action';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { dateLabel } from '@/lib/presentation';
import { cn } from '@/lib/utils';
import type { CustomerRecord } from '@/types';

type Site = { id: number; site_name: string; address: string; pic_name: string | null; pic_phone: string | null };
type Props = {
    customer: CustomerRecord & { created_at: string | null; sites_count: number; sites: Site[] };
    links: { index: string; edit: string; destroy: string };
};

export default function ShowCustomer({ customer, links }: Props) {
    const details = [
        ['Nama Customer', customer.name],
        ['Nama PIC', customer.pic_name],
        ['Telepon', customer.phone],
        ['Email', customer.email],
        ['Alamat Penagihan', customer.billing_address],
        ['Tanggal Dibuat', dateLabel(customer.created_at)],
        ['Jumlah Site', String(customer.sites_count)],
    ];

    return (
        <>
            <div className="flex flex-wrap items-center justify-end gap-3">
                <Link href={links.index} className={buttonVariants({ variant: 'outline' })}>Kembali ke Customer</Link>
                <Link href={links.edit} className={cn(buttonVariants(), 'h-10 px-4 hover:bg-primary-hover')}>Edit Customer</Link>
                <CustomerDeleteAction customer={customer} action={links.destroy} className="h-10 px-4 text-sm" />
            </div>
            <section className="panel flex flex-col gap-5" aria-labelledby="customer-details-title">
                <h2 id="customer-details-title" className="section-title">Informasi Customer</h2>
                <dl className="grid gap-5 sm:grid-cols-2">
                    {details.map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
                            <dd className="mt-1 text-sm whitespace-pre-line break-words">{value ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            </section>
            <section className="flex flex-col gap-4" aria-labelledby="customer-sites-title">
                <h2 id="customer-sites-title" className="section-title">Site Customer</h2>
                <div className="table-shell">
                    <table className="soft-table">
                        <thead><tr><th>Nama Site</th><th>Alamat</th><th>PIC</th><th>Telepon PIC</th></tr></thead>
                        <tbody>
                            {customer.sites.length ? customer.sites.map((site) => (
                                <tr key={site.id}>
                                    <td className="font-medium">{site.site_name}</td>
                                    <td className="whitespace-pre-line">{site.address}</td>
                                    <td>{site.pic_name ?? '—'}</td>
                                    <td>{site.pic_phone ?? '—'}</td>
                                </tr>
                            )) : <tr><td colSpan={4} className="empty-state">Belum ada site untuk customer ini.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </section>
        </>
    );
}

ShowCustomer.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Detail Customer" description="Informasi pelanggan dan site yang terkait.">
        {page}
    </AuthenticatedLayout>
);
