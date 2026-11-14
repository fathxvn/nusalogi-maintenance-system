import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';
export function BastReasonForm({ action, version, refreshUrl }: { action: ReportAction; version: number; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, expected_version: version, reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault(); if (form.processing || submitting.current || !form.data.reason.trim()) return;
        submitting.current = true; form.post(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: 'cancel-bast', onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label="Batalkan versi BAST" className="panel flex flex-col gap-4"><h2 className="section-title">Batalkan versi ini</h2>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} /><FormField id="bast-cancel-reason" label="Alasan pembatalan *" error={form.errors.reason}><textarea id="bast-cancel-reason" className="field-control h-auto min-h-24 py-2" required disabled={form.processing} maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></FormField>
        <Button type="submit" variant="outline" className="w-fit" disabled={form.processing || !form.data.reason.trim()} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Batalkan versi BAST'}</Button>
    </form>;
}
