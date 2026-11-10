import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { VisitFormErrors } from '@/components/visit-form-errors';
import type { UserSummary, VisitRecord } from '@/types';

type Props = { visit: VisitRecord; technicians: UserSummary[]; refreshUrl: string };

export function VisitConfirmForm({ visit, technicians, refreshUrl }: Props) {
    const form = useForm({
        command_key: visit.command_key ?? '',
        expected_visit_version: visit.lock_version,
        technician_ids: [] as number[],
    });
    const submitting = useRef(false);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!visit.confirm_url || !visit.command_key || form.processing || submitting.current || form.data.technician_ids.length === 0) return;
        submitting.current = true;
        form.post(visit.confirm_url, {
            preserveScroll: true,
            errorBag: `confirm-visit-${visit.id}`,
            onFinish: () => { submitting.current = false; },
        });
    }

    return (
        <details className="mt-4 border-t border-border pt-4">
            <summary className="w-fit text-sm font-medium text-primary">Konfirmasi peserta</summary>
            <form onSubmit={submit} aria-label={`Konfirmasi peserta kunjungan ${visit.visit_no}`} className="mt-4 flex flex-col gap-4">
                <VisitFormErrors errors={form.errors} refreshUrl={refreshUrl} />
                <fieldset disabled={form.processing} aria-describedby={`visit-${visit.id}-participant-hint`} aria-invalid={!!form.errors.technician_ids} className="flex flex-col gap-2">
                    <legend className="mb-2 text-sm font-medium">Peserta kunjungan *</legend>
                    <p id={`visit-${visit.id}-participant-hint`} className="mb-2 text-sm text-muted-foreground">
                        Pilih anggota WO yang aktif dan disetujui. Seluruh pilihan diperiksa bersama sebelum slot dipesan.
                    </p>
                    {technicians.map(technician => (
                        <label key={technician.id} className="flex min-h-11 items-center gap-3 rounded-md border border-border px-3 py-2 text-sm">
                            <input type="checkbox" name="technician_ids[]" value={technician.id} className="size-4 accent-primary"
                                checked={form.data.technician_ids.includes(technician.id)}
                                onChange={event => form.setData('technician_ids', event.target.checked
                                    ? [...form.data.technician_ids, technician.id]
                                    : form.data.technician_ids.filter(id => id !== technician.id))} />
                            <span className="min-w-0 break-words">{technician.name}</span>
                        </label>
                    ))}
                    {technicians.length === 0 && <p role="status" className="text-sm text-muted-foreground">Belum ada anggota WO yang eligible. Periksa penugasan teknisi terlebih dahulu.</p>}
                </fieldset>
                <Button type="submit" className="h-10 w-fit px-4 hover:bg-primary-hover" aria-busy={form.processing}
                    disabled={form.processing || !visit.confirm_url || !visit.command_key || form.data.technician_ids.length === 0}>
                    {form.processing ? 'Mengonfirmasi…' : 'Konfirmasi peserta'}
                </Button>
            </form>
        </details>
    );
}
