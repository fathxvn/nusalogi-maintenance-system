import { Link, useForm } from '@inertiajs/react';
import { useRef, type FormEvent, type ReactNode } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { VisitFormErrors } from '@/components/visit-form-errors';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { visitZones, withVisitOffset } from '@/lib/visit-time';
import type { VisitCreateProps } from '@/types';

export default function CreateVisit({ workOrder, commandKey, links }: VisitCreateProps) {
    return <VisitDraftForm key={workOrder.id} workOrder={workOrder} commandKey={commandKey} links={links} />;
}

export function VisitDraftForm({ workOrder, commandKey, links }: VisitCreateProps) {
    const form = useForm({ command_key: commandKey, starts_at: '', ends_at: '', zone_name: 'Asia/Jakarta', reason: '' });
    const submitting = useRef(false);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current) return;
        submitting.current = true;
        form.post(links.store, { errorBag: 'create-visit', onFinish: () => { submitting.current = false; } });
    }

    return (
        <form onSubmit={submit} aria-label="Rencanakan kunjungan" className="panel flex w-full max-w-3xl flex-col gap-5">
            <div className="border-b border-border pb-4">
                <p className="text-sm text-muted-foreground break-all">{workOrder.number}</p>
                <h2 className="section-title mt-1">{workOrder.title}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{workOrder.customer ?? '—'} · {workOrder.site ?? '—'}</p>
            </div>
            <p className="text-sm text-muted-foreground">Draft hanya menyimpan rencana waktu. Peserta dan slot teknisi ditentukan saat konfirmasi.</p>
            <VisitFormErrors errors={form.errors} refreshUrl={links.index} />
            <fieldset disabled={form.processing} className="grid min-w-0 gap-5 sm:grid-cols-2">
                <legend className="sr-only">Waktu kunjungan</legend>
                <FormField id="visit-start" label="Mulai *" error={form.errors.starts_at}>
                    <input id="visit-start" name="starts_at" type="datetime-local" required step={60} className="field-control"
                        value={form.data.starts_at.slice(0, 16)} aria-invalid={!!form.errors.starts_at}
                        aria-describedby={form.errors.starts_at ? 'visit-start-error' : undefined}
                        onChange={event => form.setData('starts_at', withVisitOffset(event.target.value, form.data.zone_name))} />
                </FormField>
                <FormField id="visit-end" label="Selesai *" error={form.errors.ends_at}>
                    <input id="visit-end" name="ends_at" type="datetime-local" required step={60} className="field-control"
                        value={form.data.ends_at.slice(0, 16)} aria-invalid={!!form.errors.ends_at}
                        aria-describedby={form.errors.ends_at ? 'visit-end-error' : undefined}
                        onChange={event => form.setData('ends_at', withVisitOffset(event.target.value, form.data.zone_name))} />
                </FormField>
                <FormField id="visit-zone" label="Zona waktu *" error={form.errors.zone_name}>
                    <select id="visit-zone" name="zone_name" className="field-control" required value={form.data.zone_name}
                        aria-invalid={!!form.errors.zone_name} aria-describedby={form.errors.zone_name ? 'visit-zone-error' : undefined}
                        onChange={event => {
                            const zone = event.target.value;
                            form.setData('starts_at', withVisitOffset(form.data.starts_at.slice(0, 16), zone));
                            form.setData('ends_at', withVisitOffset(form.data.ends_at.slice(0, 16), zone));
                            form.setData('zone_name', zone);
                        }}>
                        {visitZones.map(zone => <option key={zone.value} value={zone.value}>{zone.label}</option>)}
                    </select>
                </FormField>
            </fieldset>
            <p className="text-xs text-muted-foreground">Gunakan tanggal selesai berikutnya untuk kunjungan yang melewati tengah malam.</p>
            <FormField id="visit-reason" label="Catatan rencana (opsional)" error={form.errors.reason}>
                <textarea id="visit-reason" name="reason" className="field-control h-auto min-h-28 resize-y py-2" maxLength={10000}
                    disabled={form.processing} value={form.data.reason} aria-invalid={!!form.errors.reason}
                    aria-describedby={form.errors.reason ? 'visit-reason-error' : undefined}
                    onChange={event => form.setData('reason', event.target.value)} />
            </FormField>
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" className="h-10 px-4 hover:bg-primary-hover" disabled={form.processing} aria-busy={form.processing}>
                    {form.processing ? 'Menyimpan…' : 'Simpan draft'}
                </Button>
                <Link href={links.index} className="text-sm underline underline-offset-4">Kembali ke kunjungan</Link>
            </div>
        </form>
    );
}

CreateVisit.layout = (page: ReactNode) => <AuthenticatedLayout title="Rencanakan Kunjungan" description="Tentukan waktu kunjungan untuk Work Order.">{page}</AuthenticatedLayout>;
