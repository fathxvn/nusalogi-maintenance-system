import { Building2, CalendarDays, ClipboardList, Receipt, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { WorkOrderDistribution, WorkOrderTrend } from '@/components/dashboard/work-order-charts';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { dateLabel, priorityLabels, statusClass, workOrderLabels } from '@/lib/presentation';
import type { DashboardProps } from '@/types';

export default function Dashboard({ metrics, trend, statuses, recentWorkOrders }: DashboardProps) {
    const summaries: { label: string; value: number; detail: string; icon: LucideIcon }[] = [
        {
            label: 'Work Order Aktif',
            value: metrics.active,
            detail: 'Menunggu, ditugaskan, dan dikerjakan',
            icon: ClipboardList,
        },
        {
            label: 'Pekerjaan Hari Ini',
            value: metrics.today,
            detail: 'Berdasarkan jadwal pekerjaan',
            icon: CalendarDays,
        },
        {
            label: 'Total Customer',
            value: metrics.customers,
            detail: 'Customer terkait pekerjaan Anda',
            icon: Building2,
        },
        {
            label: 'Invoice Pending',
            value: metrics.pendingInvoices,
            detail: 'Invoice Anda yang terbit dan belum lunas',
            icon: Receipt,
        },
    ];
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {summaries.map(({ label, value, detail, icon: Icon }) => (
                    <section key={label} className="panel flex flex-col gap-4">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm text-muted-foreground">{label}</h2>
                            <span className="rounded-md bg-muted p-2 text-foreground">
                                <Icon className="size-4" aria-hidden="true" />
                            </span>
                        </div>
                        <p className="text-3xl font-semibold tracking-tight tabular-nums">
                            {value.toLocaleString('id-ID')}
                        </p>
                        <p className="text-xs leading-5 text-muted-foreground">{detail}</p>
                    </section>
                ))}
            </div>
            <div className="grid gap-5 xl:grid-cols-[1.4fr_1fr]">
                <WorkOrderTrend data={trend} />
                <WorkOrderDistribution data={statuses} />
            </div>
            <section aria-labelledby="recent-title" className="flex flex-col gap-4">
                <div>
                    <h2 id="recent-title" className="section-title">
                        Work Order Terbaru
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Maksimal delapan pekerjaan yang Anda buat atau tangani.
                    </p>
                </div>
                <div className="table-shell">
                    <table className="soft-table">
                        <thead>
                            <tr>
                                {[
                                    'Nomor / Pekerjaan',
                                    'Tanggal',
                                    'Customer / Site',
                                    'Status',
                                    'Prioritas',
                                    'Teknisi',
                                ].map((label) => (
                                    <th key={label}>{label}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {recentWorkOrders.length ? (
                                recentWorkOrders.map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <p className="font-medium">{item.number}</p>
                                            <p
                                                className="mt-1 max-w-64 truncate text-xs text-muted-foreground"
                                                title={item.title}
                                            >
                                                {item.title}
                                            </p>
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {dateLabel(item.date)}
                                        </td>
                                        <td>
                                            <p>{item.customer ?? '—'}</p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {item.site ?? '—'}
                                            </p>
                                        </td>
                                        <td>
                                            <span
                                                className={`status-badge ${statusClass(item.status)}`}
                                            >
                                                {workOrderLabels[item.status] ?? item.status}
                                            </span>
                                        </td>
                                        <td
                                            className={
                                                item.priority === 'high'
                                                    ? 'text-red-700 dark:text-red-300'
                                                    : item.priority === 'medium'
                                                      ? 'text-amber-800 dark:text-amber-300'
                                                      : 'text-muted-foreground'
                                            }
                                        >
                                            {priorityLabels[item.priority] ?? item.priority}
                                        </td>
                                        <td>{item.technicians.join(', ') || 'Belum ditugaskan'}</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={6} className="empty-state">
                                        Belum ada Work Order dalam cakupan Anda.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
        </>
    );
}
Dashboard.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Dashboard"
        description="Ringkasan pekerjaan yang Anda buat atau tangani, beserta invoice yang Anda terbitkan."
    >
        {page}
    </AuthenticatedLayout>
);
