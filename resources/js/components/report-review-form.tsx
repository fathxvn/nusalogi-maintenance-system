import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';

export function ReportReviewForm({ action, reportVersion, workOrderVersion, refreshUrl }: { action: ReportAction; reportVersion: number; workOrderVersion: number; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, expected_report_version: reportVersion, expected_work_order_version: workOrderVersion,
        decision: '', category: '', reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current || !form.data.decision) return;
        submitting.current = true;
        form.post(action.url, { preserveScroll: true, errorBag: 'review-report', onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label="Review laporan" className="panel flex flex-col gap-4">
        <h2 className="section-title">Keputusan review</h2>
        <p className="text-sm text-muted-foreground">Periksa hasil, checklist, dan evidence revisi ini sebelum mencatat keputusan QA.</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend className="sr-only">Keputusan QA</legend>
            <FormField id="review-decision" label="Keputusan *" error={form.errors.decision}>
                <select id="review-decision" className="field-control" required value={form.data.decision} onChange={event => form.setData('decision', event.target.value)}>
                    <option value="">Pilih keputusan</option><option value="approved">Setujui hasil teknis</option><option value="needs_revision">Minta revisi</option>
                </select>
            </FormField>
            {form.data.decision === 'needs_revision' && <FormField id="review-category" label="Jenis revisi *" error={form.errors.category}>
                <select id="review-category" className="field-control" required value={form.data.category} onChange={event => form.setData('category', event.target.value)}>
                    <option value="">Pilih jenis</option><option value="document_fix">Perbaikan dokumen</option><option value="technical_rework">Rework teknis di lapangan</option>
                </select>
            </FormField>}
            <FormField id="review-reason" label={form.data.decision === 'needs_revision' ? 'Alasan revisi *' : 'Catatan review (opsional)'} error={form.errors.reason}>
                <textarea id="review-reason" className="field-control h-auto min-h-24 py-2" maxLength={10000} required={form.data.decision === 'needs_revision'}
                    value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
            </FormField>
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing || !form.data.decision} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan keputusan review'}</Button>
    </form>;
}
