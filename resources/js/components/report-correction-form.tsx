import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';
import type { UserSummary } from '@/types';
export function ReportCorrectionForm({ action, reportVersion, workOrderVersion, authors, refreshUrl }: { action: ReportAction; reportVersion: number; workOrderVersion: number; authors: UserSummary[]; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, expected_report_version: reportVersion, expected_work_order_version: workOrderVersion, author_id: '', category: '', reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault(); if (form.processing || submitting.current || !form.data.author_id || !form.data.category || !form.data.reason.trim()) return;
        submitting.current = true; form.post(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: 'open-report-correction', onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label="Buka koreksi setelah QA" className="panel flex min-w-0 flex-col gap-4"><h2 className="section-title">Buka koreksi setelah QA</h2>
        <p className="text-sm text-muted-foreground">Koreksi membuat revisi baru dan menggantikan paket BAST pending yang memuat scope ini. Scope yang telah diterima customer memerlukan pekerjaan tindak lanjut tersendiri.</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-2"><legend className="sr-only">Koreksi dan penulis revisi</legend>
            <FormField id="correction-category" label="Jenis koreksi *"><select id="correction-category" required className="field-control" value={form.data.category} onChange={event => form.setData('category', event.target.value)}><option value="">Pilih jenis</option><option value="document_fix">Perbaikan dokumen</option><option value="technical_rework">Rework teknis</option></select></FormField>
            <FormField id="correction-author" label="Penulis revisi baru *"><select id="correction-author" required className="field-control" value={form.data.author_id} onChange={event => form.setData('author_id', event.target.value)}><option value="">Pilih teknisi anggota WO</option>{authors.map(author => <option key={author.id} value={author.id}>{author.name}</option>)}</select></FormField>
            <FormField id="correction-reason" label="Alasan koreksi *" error={form.errors.reason}><textarea id="correction-reason" required className="field-control h-auto min-h-24 py-2" maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></FormField>
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing || !authors.length} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Buka revisi dan tinjau ulang acceptance'}</Button>
    </form>;
}
