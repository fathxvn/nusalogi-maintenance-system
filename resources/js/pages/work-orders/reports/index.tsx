import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ReportCommandForm } from '@/components/report-command-form';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { reportStatus, type ReportIndexProps } from '@/lib/report';

export default function ReportIndex({ workOrder, scopes, legacyReports, links, unavailableReason }: ReportIndexProps) {
    return <>
        <section className="panel flex flex-col gap-3">
            <p className="text-sm text-muted-foreground break-all">{workOrder.number} · {workOrder.status}</p>
            <h2 className="section-title">{workOrder.title}</h2>
            <p className="text-sm text-muted-foreground">{workOrder.customer ?? '—'} · {workOrder.site ?? '—'}</p>
            <div className="flex flex-wrap gap-3"><Link href={links.index} className={buttonVariants({ variant: 'outline' })}>Work Order</Link><Link href={links.visits} className={buttonVariants({ variant: 'outline' })}>Kunjungan</Link></div>
            {unavailableReason && <p role="status" className="text-sm text-muted-foreground">{unavailableReason}</p>}
        </section>
        {scopes.length === 0 && <div className="panel empty-state" role="status">Belum ada scope M1 yang telah diverifikasi.</div>}
        {scopes.map(scope => <section key={scope.id} className="panel flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 className="section-title">Scope {scope.scope_no} · {scope.is_required ? 'Wajib' : 'Opsional'}</h2>
                <span className="text-sm text-muted-foreground">{scope.disposition === 'active' ? 'Aktif' : scope.disposition}</span>
            </div>
            <p className="whitespace-pre-wrap break-words">{scope.description}</p>
            <p className="text-sm text-muted-foreground whitespace-pre-wrap break-words">Kriteria penerimaan: {scope.acceptance_criteria}</p>
            {scope.revisions.length === 0 ? <p role="status" className="text-sm text-muted-foreground">Belum ada laporan untuk scope ini.</p> :
                <ol className="flex flex-col gap-3" aria-label={`Riwayat revisi scope ${scope.scope_no}`}>
                    {scope.revisions.map((revision, index) => <li key={revision.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-3">
                        <div><p className="font-medium">Revisi {revision.revision_no}{index === 0 ? ' · Terbaru' : ''}</p>
                            <p className="text-sm text-muted-foreground">{reportStatus(revision.status)} · {revision.author}</p></div>
                        <Link href={revision.url} className={buttonVariants({ variant: 'outline' })}>Buka revisi {revision.revision_no}</Link>
                    </li>)}
                </ol>}
            {scope.create && <ReportCommandForm key={`create-${scope.id}`} action={scope.create} workOrderVersion={workOrder.lock_version} label="Buat draft laporan" refreshUrl={links.refresh} />}
        </section>)}
        {legacyReports.length > 0 && <section className="panel flex flex-col gap-3"><h2 className="section-title">Laporan historis</h2>
            <p className="text-sm text-muted-foreground">Belum dipetakan ke scope atau revisi M1.</p>
            {legacyReports.map(report => <Link key={report.id} href={report.url} className="w-fit text-sm underline underline-offset-4">Laporan #{report.id} · {reportStatus(report.status)}</Link>)}
        </section>}
    </>;
}
ReportIndex.layout = (page: ReactNode) => <AuthenticatedLayout title="Laporan per Scope" description="Hasil pekerjaan, revisi, dan antrean review teknis.">{page}</AuthenticatedLayout>;
