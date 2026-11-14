import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';
export function TicketLifecycleForm({ action, kind, version, canonicalTickets, refreshUrl }: { action: ReportAction; kind: string; version: number; canonicalTickets: { id: number; ticket_number: string }[]; refreshUrl: string }) {
    const label = ({ resolve: 'Selesaikan ticket', wait_information: 'Tunggu informasi', await_authorization: 'Tunggu otorisasi', reject: 'Tolak ticket', cancel: 'Batalkan ticket', duplicate: 'Tandai duplikat' } as Record<string, string>)[kind] ?? kind;
    const form = useForm({ command_key: action.command_key, expected_ticket_version: version, note: '', ...(kind === 'duplicate' ? { duplicate_of_id: '' } : {}) });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault(); if (form.processing || submitting.current || !form.data.note.trim()) return;
        submitting.current = true; form.post(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `ticket-${kind}`, onFinish: () => { submitting.current = false; } });
    }
    return <details className="panel"><summary className="w-fit text-sm font-medium text-primary">{label}</summary><form onSubmit={submit} aria-label={label} className="mt-4 flex min-w-0 flex-col gap-4">
        {kind === 'resolve' && <p className="text-sm text-muted-foreground">Catat hasil remote yang terkonfirmasi atau pastikan seluruh WO dan scope terkait telah dituntaskan.</p>}
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="flex min-w-0 flex-col gap-4"><legend className="sr-only">Keputusan ticket</legend>
            {kind === 'duplicate' && <FormField id="canonical-ticket" label="Ticket canonical *" error={form.errors.duplicate_of_id}><select id="canonical-ticket" className="field-control" required value={form.data.duplicate_of_id ?? ''} onChange={event => form.setData('duplicate_of_id', event.target.value)}><option value="">Pilih ticket utama</option>{canonicalTickets.map(ticket => <option key={ticket.id} value={ticket.id}>{ticket.ticket_number}</option>)}</select></FormField>}
            <FormField id={`ticket-${kind}-note`} label={kind === 'resolve' ? 'Hasil penanganan / disposition yang terkonfirmasi *' : 'Alasan / catatan tindak lanjut *'} error={form.errors.note}><textarea id={`ticket-${kind}-note`} className="field-control h-auto min-h-24 py-2" required maxLength={10000} value={form.data.note} onChange={event => form.setData('note', event.target.value)} /></FormField>
        </fieldset><Button type="submit" className="w-fit" disabled={form.processing} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : label}</Button>
    </form></details>;
}
