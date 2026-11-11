import { Link } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import type { ReactNode } from 'react';

import { buttonVariants } from '@/components/ui/button';
import { VisitBookingCancelAction } from '@/components/visit-booking-cancel-action';
import { VisitConfirmForm } from '@/components/visit-confirm-form';
import { VisitFormErrors } from '@/components/visit-form-errors';
import { VisitRescheduleAction } from '@/components/visit-reschedule-action';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { statusClass, workOrderLabels } from '@/lib/presentation';
import { cn } from '@/lib/utils';
import { visitDateLabel, visitStatusLabels } from '@/lib/visit-time';
import type { VisitIndexProps } from '@/types';

export default function VisitIndex({ workOrder, members, technicians, visits, unavailableReason, links, errors = {} }: VisitIndexProps) {
    return (
        <>
            <VisitFormErrors errors={errors} refreshUrl={links.refresh} />
            <section className="panel flex flex-col gap-4" aria-label="Work Order dan anggota">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-xs text-muted-foreground break-all">{workOrder.number}</p>
                        <h2 className="section-title mt-1 break-words">{workOrder.title}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">{workOrder.customer ?? '—'} · {workOrder.site ?? '—'}</p>
                    </div>
                    <span className={cn('status-badge', statusClass(workOrder.status))}>WO · {workOrderLabels[workOrder.status] ?? workOrder.status}</span>
                </div>
                <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4 text-sm">
                    <span className="text-muted-foreground">Anggota WO:</span>
                    {members.map(member => <span key={member.id} className="rounded-md bg-muted px-2 py-1 break-words">{member.name}</span>)}
                    {members.length === 0 && <span className="text-muted-foreground">Belum ada penugasan.</span>}
                </div>
            </section>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">{visits.total} kunjungan · Halaman {visits.current_page} dari {visits.last_page}</p>
                {links.create && <Link href={links.create} className={cn(buttonVariants(), 'min-h-10 px-4 hover:bg-primary-hover')}><Plus aria-hidden="true" />Rencanakan kunjungan</Link>}
            </div>
            {unavailableReason && <p role="status" className="rounded-md bg-muted p-4 text-sm text-muted-foreground">{unavailableReason}</p>}
            <div className="flex flex-col gap-5">
                {visits.data.length === 0 && <p className="panel empty-state">Belum ada kunjungan untuk Work Order ini.</p>}
                {visits.data.map(visit => (
                    <article key={visit.id} className="panel" aria-label={`Kunjungan ${visit.visit_no}`}>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="section-title">Kunjungan {visit.visit_no}</h2>
                            <span className={cn('status-badge', visit.status === 'confirmed' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')}>
                                {visitStatusLabels[visit.status] ?? visit.status}
                            </span>
                        </div>
                        <div className="mt-4 flex items-start gap-2 text-sm">
                            <CalendarDays aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0">
                                <p>{visitDateLabel(visit.starts_at, visit.zone_name)} — {visitDateLabel(visit.ends_at, visit.zone_name)}</p>
                                <p className="mt-1 text-xs text-muted-foreground">{visit.zone_name}</p>
                            </div>
                        </div>
                        {visit.previous_visit_no !== null && <p className="mt-3 text-sm text-muted-foreground">Menggantikan kunjungan {visit.previous_visit_no}.</p>}
                        {visit.successor_visit_no != null && <p className="mt-3 text-sm text-muted-foreground">Diganti oleh kunjungan {visit.successor_visit_no}.</p>}
                        {visit.reason && <p className="mt-3 text-sm break-words whitespace-pre-wrap">{visit.reason}</p>}
                        {visit.bookings.length > 0 ? (
                            <ul className="mt-4 divide-y divide-border border-t border-border">
                                {visit.bookings.map(booking => (
                                    <li key={booking.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div className="min-w-0 flex-1 break-words text-sm">
                                            <p className="font-medium">{booking.technician_name}</p>
                                            <p className="text-xs text-muted-foreground">{visitStatusLabels[booking.status] ?? booking.status}</p>
                                            {booking.ended_reason && <p className="mt-1 whitespace-pre-wrap">Alasan: {booking.ended_reason}</p>}
                                        </div>
                                        {booking.cancel_url && booking.command_key && <VisitBookingCancelAction key={`${booking.id}:${visit.lock_version}`} booking={booking} visitVersion={visit.lock_version} refreshUrl={links.refresh} />}
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="mt-4 text-sm text-muted-foreground">Belum ada booking peserta.</p>}
                        {visit.confirm_url && visit.command_key && <VisitConfirmForm key={`${visit.id}:${visit.lock_version}`} visit={visit} technicians={technicians} refreshUrl={links.refresh} />}
                        {visit.reschedule_url && visit.reschedule_command_key && <VisitRescheduleAction key={`reschedule:${visit.id}`} visit={visit} technicians={technicians} refreshUrl={links.refresh} />}
                    </article>
                ))}
            </div>
            <nav aria-label="Pagination kunjungan" className="flex flex-wrap items-center justify-between gap-3">
                <Link href={links.index} className="text-sm underline underline-offset-4">Kembali ke Work Order</Link>
                <div className="flex flex-wrap gap-2">
                    {visits.prev_page_url && <Link href={visits.prev_page_url} className={buttonVariants({ variant: 'outline' })}>Sebelumnya</Link>}
                    {visits.next_page_url && <Link href={visits.next_page_url} className={buttonVariants({ variant: 'outline' })}>Berikutnya</Link>}
                </div>
            </nav>
        </>
    );
}

VisitIndex.layout = (page: ReactNode) => <AuthenticatedLayout title="Kunjungan Work Order" description="Kelola rencana kunjungan dan booking peserta.">{page}</AuthenticatedLayout>;
