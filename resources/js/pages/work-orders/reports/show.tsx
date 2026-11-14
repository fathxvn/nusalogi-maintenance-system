import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ReportCorrectionForm } from '@/components/report-correction-form';
import { ReportCommandForm } from '@/components/report-command-form';
import { ReportDraftForm } from '@/components/report-draft-form';
import { ReportEvidenceDetachForm } from '@/components/report-evidence-detach-form';
import { ReportEvidenceForm } from '@/components/report-evidence-form';
import { ReportMaterialForm } from '@/components/report-material-form';
import { ReportReviewForm } from '@/components/report-review-form';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { reportStatus, reportTime, type MaterialMovement, type ReportShowProps } from '@/lib/report';

function MaterialList({ movements, empty }: { movements: MaterialMovement[]; empty: string }) {
    return movements.length ? <ul className="flex flex-col gap-3">{movements.map(item => <li key={item.id} className="rounded-md border border-border p-3 text-sm">
        <p className="font-medium">#{item.id} · {item.item_description} · {item.movement_kind === 'consume' ? '+' : '−'}{item.quantity} {item.unit_code}</p>
        <p className="text-muted-foreground">{item.movement_kind === 'consume' ? 'Pemakaian' : item.movement_kind === 'return' ? 'Pengembalian' : 'Reversal'}{item.adjusts_usage_id ? ` atas #${item.adjusts_usage_id}` : ''} · {item.disposition}</p>
        {item.reason && <p className="mt-1 whitespace-pre-wrap break-words">{item.reason}</p>}
    </li>)}</ul> : <p role="status" className="text-sm text-muted-foreground">{empty}</p>;
}

export default function ReportShow({ workOrder, report, revisions, materials, assets, actions, links, correctionAuthors = [] }: ReportShowProps) {
    return <>
        <section className="panel flex min-w-0 flex-col gap-3">
            <Link href={links.index} className="w-fit text-sm underline underline-offset-4">Kembali ke scope dan riwayat laporan</Link>
            <p className="text-sm text-muted-foreground break-all">{workOrder.number} · {workOrder.status}</p>
            <h2 className="section-title">{report.legacy ? `Laporan historis #${report.id}` : `Laporan · Revisi ${report.revision_no}`}</h2>
            <p className="text-sm">{reportStatus(report.review_status)}{report.is_head ? ' · Revisi terbaru' : ''} · Penulis: {report.author}</p>
            {report.scope && <div className="flex flex-col gap-2 text-sm"><p className="whitespace-pre-wrap break-words">Scope {report.scope.scope_no}: {report.scope.description}</p><p className="text-muted-foreground whitespace-pre-wrap break-words">Kriteria penerimaan: {report.scope.acceptance_criteria}</p></div>}
            {report.legacy && <p role="status" className="text-sm text-muted-foreground">Data historis belum dipetakan ke scope M1. Tidak digunakan sebagai bukti closure baru.</p>}
            <nav aria-label="Riwayat revisi" className="flex flex-wrap gap-2">{revisions.map(revision => <Link key={revision.id} href={revision.url} aria-current={revision.id === report.id ? 'page' : undefined} className={buttonVariants({ variant: 'outline' })}>Revisi {revision.revision_no} · {reportStatus(revision.status)}</Link>)}</nav>
            <p className="text-sm text-muted-foreground">Diajukan: {reportTime(report.submitted_at)} · Direview: {reportTime(report.reviewed_at)}{report.reviewer ? ` oleh ${report.reviewer}` : ''}</p>
            {report.revision_category && <p className="text-sm">Tindak lanjut QA: {report.revision_category === 'technical_rework' ? 'Rework teknis' : 'Perbaikan dokumen'}</p>}
            {report.review_notes && <p className="rounded-md bg-muted p-3 text-sm whitespace-pre-wrap break-words">Catatan QA: {report.review_notes}</p>}
        </section>
        {actions.update ? <ReportDraftForm key={`edit-${report.id}`} report={report} action={actions.update} assets={assets} refreshUrl={links.refresh} /> : <section className="panel grid min-w-0 gap-5 md:grid-cols-2">
            {([['Diagnosis', report.diagnosis], ['Tindakan', report.action_taken], ['Hasil', report.result], ['Rekomendasi', report.recommendation]] as const).map(([label, value]) => <div key={label}><h3 className="font-medium">{label}</h3><p className="mt-2 text-sm whitespace-pre-wrap break-words">{value || '—'}</p></div>)}
            <div><h3 className="font-medium">Checklist</h3>{report.checklist_snapshot?.length ? <ul className="mt-2 flex flex-col gap-2 text-sm">{report.checklist_snapshot.map(item => <li key={item.code}>{item.label}: {({ pass: 'Lulus', fail: 'Tidak lulus', not_applicable: 'Tidak relevan' } as Record<string, string>)[item.outcome] ?? item.outcome}{item.notes ? ` · ${item.notes}` : ''}</li>)}</ul> : <p className="mt-2 text-sm text-muted-foreground">Belum ada checklist.</p>}</div>
            {!!report.asset_results?.length && <div><h3 className="font-medium">Hasil per aset</h3><ul className="mt-2 flex flex-col gap-2 text-sm">{report.asset_results.map(item => <li key={item.asset_id}>{assets.find(asset => asset.id === item.asset_id)?.asset_code ?? `Aset #${item.asset_id}`}: {item.result}{item.notes ? ` · ${item.notes}` : ''}</li>)}</ul></div>}
        </section>}
        <section className="panel flex min-w-0 flex-col gap-3"><h2 className="section-title">Foto dan file evidence</h2>
            {report.photos.length === 0 ? <p role="status" className="text-sm text-muted-foreground">Belum ada evidence terlampir.</p> : <ul className="flex flex-col gap-3">{report.photos.map(photo => <li key={photo.id} className="rounded-md border border-border p-3 text-sm break-words">
                {photo.download_url ? <a href={photo.download_url} className="underline underline-offset-4">Unduh {photo.name}</a> : <span>{photo.name}</span>}
                <p className="mt-1 text-muted-foreground">{({ before: 'Sebelum', after: 'Sesudah', other: 'Lainnya' } as Record<string, string>)[photo.phase] ?? photo.phase}{photo.caption ? ` · ${photo.caption}` : ''}</p>
                {photo.detach && <ReportEvidenceDetachForm action={photo.detach} reportVersion={report.lock_version} photoId={photo.id} refreshUrl={links.refresh} />}
            </li>)}</ul>}
        </section>
        {actions.update && <p className="text-sm text-muted-foreground">Simpan perubahan draft sebelum menambahkan evidence atau mencatat material.</p>}
        {actions.evidence && <ReportEvidenceForm key={`evidence-${report.id}`} action={actions.evidence} reportVersion={report.lock_version} refreshUrl={links.refresh} />}
        <section className="panel flex flex-col gap-3"><h2 className="section-title">Riwayat pemakaian material</h2><MaterialList movements={materials} empty="Belum ada pemakaian material yang dicatat." /></section>
        {report.usage_snapshot && <section className="panel flex flex-col gap-3"><h2 className="section-title">Material saat pengajuan revisi ini</h2><MaterialList movements={report.usage_snapshot} empty="Tidak ada pemakaian material saat revisi ini diajukan." /></section>}
        {actions.material && <ReportMaterialForm key={`material-${report.id}`} action={actions.material} reportVersion={report.lock_version} movements={materials} refreshUrl={links.refresh} />}
        {actions.submit && <section className="panel"><ReportCommandForm key={`submit-${report.id}`} action={actions.submit} reportVersion={report.lock_version} workOrderVersion={workOrder.lock_version} refreshUrl={links.refresh} label="Ajukan laporan tersimpan"
            description="Simpan perubahan sebelum mengajukan laporan. Isi, checklist, material saat pengajuan, dan evidence revisi ini akan dibekukan untuk review." /></section>}
        {actions.revise && <section className="panel"><ReportCommandForm key={`revise-${report.id}`} action={actions.revise} reportVersion={report.lock_version} workOrderVersion={workOrder.lock_version} refreshUrl={links.refresh} label="Mulai revisi baru"
            description="Revisi baru mempertahankan laporan dan keputusan review sebelumnya. Rework teknis memerlukan sesi kerja lanjutan sebelum diajukan kembali." /></section>}
        {actions.correction && <ReportCorrectionForm key={`correction-${report.id}`} action={actions.correction} reportVersion={report.lock_version} workOrderVersion={workOrder.lock_version} authors={correctionAuthors} refreshUrl={links.refresh} />}
        {actions.review && <ReportReviewForm key={`review-${report.id}`} action={actions.review} reportVersion={report.lock_version} workOrderVersion={workOrder.lock_version} refreshUrl={links.refresh} />}
    </>;
}
ReportShow.layout = (page: ReactNode) => <AuthenticatedLayout title="Detail Laporan" description="Evidence hasil kerja dan keputusan QA untuk satu revisi.">{page}</AuthenticatedLayout>;
