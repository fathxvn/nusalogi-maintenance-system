import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BastEvidenceForm } from '@/components/bast-evidence-form';
import { BastReasonForm } from '@/components/bast-reason-form';
import { BastScopeForm } from '@/components/bast-scope-form';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { bastStatus, type BastShowProps } from '@/lib/acceptance';
import { reportTime } from '@/lib/report';
export default function BastShow({ version, versions, items, candidates, actions, links }: BastShowProps) {
    return <><section className="panel flex min-w-0 flex-col gap-3"><Link href={links.index} className="w-fit text-sm underline underline-offset-4">Kembali ke daftar BAST</Link><h2 className="section-title break-all">{version.number} · Versi {version.version_no}</h2><p className="font-medium">{bastStatus(version.status)}</p><p className="text-sm">{version.customer}</p><p className="text-sm text-muted-foreground break-all">Konteks: {version.context}</p>
        <nav className="flex flex-wrap gap-2" aria-label="Versi BAST">{versions.map(other => <Link key={other.id} href={other.url} aria-current={other.id === version.id ? 'page' : undefined} className={buttonVariants({ variant: 'outline' })}>Versi {other.version_no} · {bastStatus(other.status)}</Link>)}</nav>
        <p className="text-sm text-muted-foreground">Diterbitkan: {reportTime(version.issued_at)}</p>
        {version.issued_url && <a href={version.issued_url} className="w-fit text-sm underline underline-offset-4">Unduh dokumen yang diajukan</a>}
        {version.reason && <p className="rounded-md bg-muted p-3 text-sm whitespace-pre-wrap break-words">{version.reason}</p>}
    </section>
    <section className="panel flex min-w-0 flex-col gap-4"><h2 className="section-title">Scope versi ini</h2>{items.length ? <ol className="flex flex-col gap-3">{items.map(item => <li key={item.scope_id} className="rounded-md border border-border p-3 text-sm"><p className="font-medium break-all">{item.work_order_number} · Laporan revisi {item.revision_no}</p><p className="mt-2 whitespace-pre-wrap break-words">{item.description}</p><p className="mt-1 text-muted-foreground whitespace-pre-wrap break-words">Kriteria penerimaan: {item.acceptance_criteria}</p>{item.report_url && <Link href={item.report_url} className="mt-2 inline-block underline underline-offset-4">Periksa revisi laporan</Link>}</li>)}</ol> : <p role="status" className="text-sm text-muted-foreground">Draft belum memiliki item scope.</p>}</section>
    {version.decided_at && <section className="panel flex flex-col gap-3"><h2 className="section-title">Bukti keputusan customer</h2><p className="text-sm">Penanda tangan: {version.signer_name} · {version.signer_capacity}</p><p className="text-sm break-words">Referensi kewenangan: {version.authority_ref}</p><p className="text-sm">Keputusan customer: {reportTime(version.decided_at)}</p><p className="text-sm">Direkam: {reportTime(version.recorded_at)} · {version.recorder}</p>{version.decision_url && <a href={version.decision_url} className="w-fit text-sm underline underline-offset-4">Unduh bukti keputusan</a>}</section>}
    {actions.update && <BastScopeForm key={`update-${version.id}`} action={actions.update} candidates={candidates} initial={items} version={version.lock_version} method="patch" label="Perbarui scope draft" refreshUrl={links.refresh} />}
    {actions.issue && <BastEvidenceForm key={`issue-${version.id}`} action={actions.issue} version={version} items={items} refreshUrl={links.refresh} />}
    {actions.decide && <BastEvidenceForm key={`decide-${version.id}`} action={actions.decide} version={version} items={items} decision refreshUrl={links.refresh} />}
    {actions.revise && <BastScopeForm key={`revise-${version.id}`} action={actions.revise} candidates={candidates} initial={items} version={version.lock_version} label="Buat versi pengganti" reasonRequired refreshUrl={links.refresh} />}
    {actions.cancel && <BastReasonForm key={`cancel-${version.id}`} action={actions.cancel} version={version.lock_version} refreshUrl={links.refresh} />}
    </>;
}
BastShow.layout = (page: ReactNode) => <AuthenticatedLayout title="Versi BAST" description="Scope yang diajukan dan jejak penerimaan customer untuk versi dokumen ini.">{page}</AuthenticatedLayout>;
