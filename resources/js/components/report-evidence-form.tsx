import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';

export function ReportEvidenceForm({ action, reportVersion, refreshUrl }: { action: ReportAction; reportVersion: number; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, upload_key: action.upload_key ?? '', expected_report_version: reportVersion,
        file: null as File | null, sha256: '', phase: 'other', caption: '' });
    const submitting = useRef(false);
    const selection = useRef(0);
    const [hashing, setHashing] = useState(false);
    async function selectFile(file: File | null) {
        const token = ++selection.current;
        form.setData('file', file);
        form.setData('sha256', '');
        form.clearErrors('file');
        if (!file) return;
        setHashing(true);
        try {
            const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            if (token !== selection.current) return;
            form.setData('sha256', [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join(''));
            form.setData('upload_key', crypto.randomUUID());
            form.setData('command_key', crypto.randomUUID());
        } catch {
            if (token === selection.current) form.setError('file', 'File belum dapat diproses. Pilih ulang file melalui koneksi yang aman.');
        } finally {
            if (token === selection.current) setHashing(false);
        }
    }
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || hashing || submitting.current || !form.data.file || form.data.sha256.length !== 64) return;
        submitting.current = true;
        form.post(action.url, { forceFormData: true, preserveScroll: true, preserveState: 'errors', errorBag: 'report-evidence', onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label="Unggah evidence privat" className="panel flex flex-col gap-4">
        <h2 className="section-title">Evidence privat</h2>
        <p className="text-sm text-muted-foreground">Foto JPG, PNG, WebP atau dokumen PDF, maksimal 10 MB. Evidence revisi yang diajukan akan dipertahankan.</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing || hashing} className="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend className="sr-only">File evidence</legend>
            <FormField id="evidence-file" label="Foto / file *" error={form.errors.file}>
                <input id="evidence-file" className="field-control h-auto py-2" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required onChange={event => { void selectFile(event.target.files?.[0] ?? null); }} />
            </FormField>
            <FormField id="evidence-phase" label="Tahap dokumentasi">
                <select id="evidence-phase" className="field-control" value={form.data.phase} onChange={event => form.setData('phase', event.target.value)}>
                    <option value="before">Sebelum</option><option value="after">Sesudah</option><option value="other">Lainnya / dokumen</option>
                </select>
            </FormField>
            <FormField id="evidence-caption" label="Keterangan (opsional)" error={form.errors.caption}>
                <textarea id="evidence-caption" className="field-control h-auto min-h-20 py-2" maxLength={2000} value={form.data.caption} onChange={event => form.setData('caption', event.target.value)} />
            </FormField>
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing || hashing || !form.data.file || form.data.sha256.length !== 64} aria-busy={form.processing || hashing}>
            {hashing ? 'Memeriksa file…' : form.processing ? 'Mengunggah…' : 'Unggah evidence'}
        </Button>
    </form>;
}
