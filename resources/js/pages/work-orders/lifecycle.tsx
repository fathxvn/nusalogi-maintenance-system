import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { WorkOrderLifecycleForm, type LifecycleAction } from '@/components/work-order-lifecycle-form';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { reportStatus, reportTime, type ReportAction } from '@/lib/report';
type Props = {
    workOrder: { id: number; number: string; title: string; status: string; lock_version: number; context: string | null; closed_at: string | null; customer: string | null; site: string | null };
    scopes: { id: number; description: string; is_required: boolean; disposition: string; head_revision: number | null; head_status: string | null; accepted: boolean }[];
    history: { id: number; from: string | null; to: string; notes: string | null; actor: string | null; at: string | null }[];
    closureEvidence: { scope_item_id: number }[]; unavailableReason: string | null;
    actions: Record<LifecycleAction, ReportAction | null>; links: { reports: string; visits: string; acceptance: string; refresh: string };
};
const statusLabel = (status: string | null) => status ? ({ pending: 'Menunggu penugasan', assigned: 'Ditugaskan', in_progress: 'Dikerjakan', pending_review: 'Menunggu QA', rework: 'Perlu rework', awaiting_acceptance: 'Menunggu acceptance', on_hold: 'Ditahan', closed: 'Ditutup', cancelled: 'Dibatalkan' } as Record<string, string>)[status] ?? status : '—';
export default function WorkOrderLifecycle({ workOrder, scopes, history, closureEvidence, actions, links, unavailableReason }: Props) {
    const required = scopes.filter(scope => scope.is_required && scope.disposition === 'active');
    const canClose = required.length > 0 && required.every(scope => scope.accepted);
    return <><section className="panel flex min-w-0 flex-col gap-3"><p className="text-sm text-muted-foreground break-all">{workOrder.number} · {workOrder.customer ?? '—'} · {workOrder.site ?? '—'}</p><h2 className="section-title">{workOrder.title}</h2><p className="font-medium">{statusLabel(workOrder.status)}</p><p className="text-sm text-muted-foreground break-all">Konteks acceptance: {workOrder.context ?? 'Belum diverifikasi'}</p>
        {workOrder.closed_at && <p className="text-sm">Ditutup: {reportTime(workOrder.closed_at)} · Bukti tersimpan untuk {closureEvidence.length} scope wajib.</p>}
        <nav aria-label="Detail WO" className="flex flex-wrap gap-2"><Link href={links.reports} className={buttonVariants({ variant: 'outline' })}>Laporan</Link><Link href={links.visits} className={buttonVariants({ variant: 'outline' })}>Kunjungan</Link><Link href={links.acceptance} className={buttonVariants({ variant: 'outline' })}>BAST / Acceptance</Link></nav>
        {unavailableReason && <p role="status" className="text-sm text-muted-foreground">{unavailableReason}</p>}
    </section>
    <section className="panel flex min-w-0 flex-col gap-3"><h2 className="section-title">Kesiapan scope</h2>{scopes.length ? <ul className="flex flex-col gap-3">{scopes.map(scope => <li key={scope.id} className="rounded-md border border-border p-3 text-sm"><p className="font-medium whitespace-pre-wrap break-words">{scope.description}</p><p className="mt-1 text-muted-foreground">{scope.is_required ? 'Wajib' : 'Opsional'} · {scope.disposition} · {scope.head_status ? `Revisi ${scope.head_revision}: ${reportStatus(scope.head_status)}` : 'Belum ada laporan'} · {scope.accepted ? 'Diterima customer' : 'Acceptance belum lengkap'}</p></li>)}</ul> : <p role="status" className="text-sm text-muted-foreground">Belum ada scope M1 yang terverifikasi.</p>}</section>
    {(['hold', 'resume', 'context', 'close', 'cancel'] as const).map(kind => actions[kind] && <WorkOrderLifecycleForm key={`${workOrder.id}-${kind}`} action={actions[kind]} kind={kind} version={workOrder.lock_version} context={workOrder.context} refreshUrl={links.refresh} disabled={kind === 'close' && !canClose} />)}
    <section className="panel flex flex-col gap-3"><h2 className="section-title">Riwayat status</h2>{history.length ? <ol className="flex flex-col gap-3">{history.map(entry => <li key={entry.id} className="rounded-md border border-border p-3 text-sm"><p className="font-medium">{statusLabel(entry.from)} → {statusLabel(entry.to)}</p><p className="mt-1 text-muted-foreground">{reportTime(entry.at)} · {entry.actor ?? 'Pelaku historis'}</p>{entry.notes && <p className="mt-2 whitespace-pre-wrap break-words">{entry.notes}</p>}</li>)}</ol> : <p role="status" className="text-sm text-muted-foreground">Belum ada perubahan status tercatat.</p>}</section>
    </>;
}
WorkOrderLifecycle.layout = (page: ReactNode) => <AuthenticatedLayout title="Lifecycle dan Penutupan WO" description="Hold, tindak lanjut, acceptance scope, dan dasar penutupan pekerjaan.">{page}</AuthenticatedLayout>;
