
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { buttonVariants } from '@/components/ui/button';
import type { Paginated } from '@/types';

type WorkOrder = {
    id: number;
    number: string;
    title: string;
    customer: string | null;
    site: string | null;
    service_type: string;
    priority: string;
    status: string;
    scheduled_at: string | null;
    technicians: string[];
};

type Props = {
    workOrders: Paginated<WorkOrder>;
    links: { create: string | null };
};

export default function WorkOrderIndex({ workOrders, links }: Props) {
    return (
        <>
            <section className="panel flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 className="section-title">
                        Daftar Work Order
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Total {workOrders.total} pekerjaan
                    </p>
                </div>
                {links.create && (
                    <Link href={links.create} className={buttonVariants()}>
                        Tambah Work Order
                    </Link>
                )}
            </section>

            <div className="table-shell">
                <table className="soft-table">
                    <thead>
                        <tr>
                            <th>Nomor WO</th>
                            <th>Pekerjaan</th>
                            <th>Customer / Site</th>
                            <th>Teknisi</th>
                            <th>Prioritas</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {workOrders.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="empty-state">
                                    Belum ada Work Order.
                                </td>
                            </tr>
                        ) : (
                            workOrders.data.map((wo) => (
                                <tr key={wo.id}>
                                    <td className="font-medium">
                                        {wo.number}
                                    </td>
                                    <td>
                                        <p>{wo.title}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {wo.service_type}
                                        </p>
                                    </td>
                                    <td>
                                        {wo.customer ?? '—'}
                                        <p className="text-xs text-muted-foreground">
                                            {wo.site ?? '—'}
                                        </p>
                                    </td>
                                    <td>
                                        {wo.technicians.join(', ') || 'Belum ditugaskan'}
                                    </td>
                                    <td>{wo.priority}</td>
                                    <td>{wo.status}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <nav
                aria-label="Pagination Work Order"
                className="flex items-center justify-between gap-3 text-sm"
            >
                <span>
                    Halaman {workOrders.current_page} dari{' '}
                    {workOrders.last_page}
                </span>
                <div className="flex gap-2">
                    {workOrders.prev_page_url && (
                        <Link
                            href={workOrders.prev_page_url}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Sebelumnya
                        </Link>
                    )}
                    {workOrders.next_page_url && (
                        <Link
                            href={workOrders.next_page_url}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Berikutnya
                        </Link>
                    )}
                </div>
            </nav>
        </>
    );
}

WorkOrderIndex.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Work Order Management"
        description="Kelola dan pantau pekerjaan teknisi Nusalogi."
    >
        {page}
    </AuthenticatedLayout>
);
