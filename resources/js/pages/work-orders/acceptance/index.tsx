import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BastScopeForm } from '@/components/bast-scope-form';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { bastStatus, type BastIndexProps } from '@/lib/acceptance';
export default function BastIndex({ workOrder, candidates, versions, create, links }: BastIndexProps) {
    return <><section className="panel flex flex-col gap-3"><Link href={links.reports} className="w-fit text-sm underline underline-offset-4">Kembali ke laporan WO</Link><p className="text-sm text-muted-foreground break-all">{workOrder.number}</p><h2 className="section-title">BAST dan penerimaan customer</h2><p>{workOrder.title}</p></section>
        <section className="panel flex min-w-0 flex-col gap-3"><h2 className="section-title">Dokumen terkait</h2>
            {versions.data.length ? <ul className="flex flex-col gap-3">{versions.data.map(version => <li key={version.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-3 text-sm"><div><p className="font-medium break-all">{version.number} · Versi {version.version_no}</p><p className="text-muted-foreground">{bastStatus(version.status)}</p></div><Link href={version.url} className={buttonVariants({ variant: 'outline' })}>Buka versi</Link></li>)}</ul> : <p role="status" className="text-sm text-muted-foreground">Belum ada dokumen BAST yang dapat diakses untuk WO ini.</p>}
            <nav aria-label="Halaman BAST" className="flex flex-wrap items-center justify-between gap-3 text-sm"><span>Halaman {versions.current_page} dari {versions.last_page}</span><div className="flex gap-3">{versions.prev_page_url && <Link href={versions.prev_page_url} className="underline">Sebelumnya</Link>}{versions.next_page_url && <Link href={versions.next_page_url} className="underline">Berikutnya</Link>}</div></nav>
        </section>
        {create && <BastScopeForm key={`new-bast-${workOrder.id}`} action={create} candidates={candidates} label="Buat draft BAST" refreshUrl={links.refresh} />}
    </>;
}
BastIndex.layout = (page: ReactNode) => <AuthenticatedLayout title="Customer Acceptance" description="Paket scope, dokumen BAST berversi, dan bukti keputusan customer.">{page}</AuthenticatedLayout>;
