import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
type Ticket = { id: number; number: string; status: string; contact: string; site: string | null; show_url: string };
type Props = { tickets: { data: Ticket[]; current_page: number; total: number; prev_page_url: string | null; next_page_url: string | null }; createUrl: string | null };
export default function Tickets({ tickets, createUrl }: Props) {
    return <div className="panel flex flex-col gap-4">{createUrl && <Link href={createUrl} className="underline">Catat Ticket</Link>}
        <p>{tickets.total} ticket · Halaman {tickets.current_page}</p>
        {tickets.data.length === 0 && <p>Belum ada ticket.</p>}
        {tickets.data.map(ticket => <article key={ticket.id} className="flex flex-wrap justify-between gap-3 border-b border-border py-3"><Link href={ticket.show_url} className="underline">{ticket.number}</Link><span>{ticket.contact}</span><span>{ticket.site ?? 'Site belum diketahui'}</span><span>{ticket.status}</span></article>)}
        <nav aria-label="Halaman ticket" className="flex gap-4">{tickets.prev_page_url && <Link href={tickets.prev_page_url}>Sebelumnya</Link>}{tickets.next_page_url && <Link href={tickets.next_page_url}>Berikutnya</Link>}</nav>
    </div>;
}
Tickets.layout = (page: ReactNode) => <AuthenticatedLayout title="Ticket" description="Intake dan persetujuan operasional.">{page}</AuthenticatedLayout>;
