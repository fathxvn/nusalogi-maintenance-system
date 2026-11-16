import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';

export function ReportEvidenceDetachForm({ action, reportVersion, photoId, refreshUrl }: { action: ReportAction; reportVersion: number; photoId: number; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, expected_report_version: reportVersion, reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current || !form.data.reason.trim()) return;
        submitting.current = true;
        form.delete(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `detach-${photoId}`, onFinish: () => { submitting.current = false; } });
    }
    return <details className="mt-3"><summary className="w-fit font-medium text-primary">Lepas dari draft ini</summary>
        <form onSubmit={submit} aria-label={`Lepas lampiran ${photoId}`} className="mt-3 flex flex-col gap-3">
            <p className="text-muted-foreground">File dan lampiran pada revisi lama tetap tersimpan.</p>
            <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
            <FormField id={`detach-reason-${photoId}`} label="Alasan pelepasan *" error={form.errors.reason}>
                <textarea id={`detach-reason-${photoId}`} required disabled={form.processing} className="field-control h-auto min-h-20 py-2" maxLength={2000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
            </FormField>
            <Button variant="outline" className="w-fit" type="submit" disabled={form.processing} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Lepas lampiran draft'}</Button>
        </form>
    </details>;
}
