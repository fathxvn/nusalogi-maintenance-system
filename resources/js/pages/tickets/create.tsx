import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';

type Props = { commandKey: string; sites: { id: number; site_name: string }[]; links: { store: string; index: string } };
export default function CreateTicket({ commandKey, sites, links }: Props) {
    const form = useForm({ command_key: commandKey, kind: 'incident', channel: '', contact_label: '', description: '', received_at: '', site_id: '' });
    function submit(event: FormEvent) { event.preventDefault(); if (!form.processing) { form.post(links.store); } }
    return <form onSubmit={submit} aria-label="Catat Ticket" className="panel flex max-w-3xl flex-col gap-5">
        <FormField id="kind" label="Jenis *" error={form.errors.kind}><select id="kind" name="kind" className="field-control" value={form.data.kind} onChange={e => form.setData('kind', e.target.value)}>{['request', 'incident', 'complaint'].map(kind => <option key={kind}>{kind}</option>)}</select></FormField>
        <FormField id="contact" label="Kontak pelapor *" error={form.errors.contact_label}><input id="contact" name="contact_label" required maxLength={150} className="field-control" value={form.data.contact_label} onChange={e => form.setData('contact_label', e.target.value)} /></FormField>
        <FormField id="channel" label="Kanal *" error={form.errors.channel}><input id="channel" name="channel" required maxLength={30} className="field-control" value={form.data.channel} onChange={e => form.setData('channel', e.target.value)} /></FormField>
        <FormField id="site" label="Site (boleh belum diketahui)" error={form.errors.site_id}><select id="site" name="site_id" className="field-control" value={form.data.site_id} onChange={e => form.setData('site_id', e.target.value)}><option value="">Belum diketahui</option>{sites.map(site => <option key={site.id} value={site.id}>{site.site_name} (#{site.id})</option>)}</select></FormField>
        <FormField id="received" label="Diterima (UTC, kosong = sekarang)" error={form.errors.received_at}><input id="received" name="received_at" type="datetime-local" className="field-control" value={form.data.received_at} onChange={e => form.setData('received_at', e.target.value)} /></FormField>
        <FormField id="description" label="Keluhan / kebutuhan *" error={form.errors.description}><textarea id="description" name="description" required maxLength={10000} className="field-control" value={form.data.description} onChange={e => form.setData('description', e.target.value)} /></FormField>
        {form.errors.command_key && <p role="alert">{form.errors.command_key}</p>}
        <p className="text-sm text-muted-foreground">Mencatat ticket tidak otomatis membuat Work Order.</p>
        <div className="flex gap-4"><Button type="submit" disabled={form.processing}>{form.processing ? 'Menyimpan…' : 'Catat Ticket'}</Button><Link href={links.index}>Batal</Link></div>
    </form>;
}
CreateTicket.layout = (page: ReactNode) => <AuthenticatedLayout title="Catat Ticket" description="Catat kebutuhan pelanggan untuk ditriage Koordinator.">{page}</AuthenticatedLayout>;
