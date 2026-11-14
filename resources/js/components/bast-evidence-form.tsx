import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { BastScope, BastVersion } from '@/lib/acceptance';
import type { ReportAction } from '@/lib/report';
import { withVisitOffset } from '@/lib/visit-time';

export function BastEvidenceForm({ action, version, items, decision = false, refreshUrl }: { action: ReportAction; version: BastVersion; items: BastScope[]; decision?: boolean; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, upload_key: action.upload_key ?? '', expected_version: version.lock_version,
        file: null as File | null, sha256: '', scope_confirmed: false, signer_name: '', signer_capacity: '', authority_ref: '',
        authorized_site_ids: [...new Set(items.map(item => item.site_id))], decision: '', decided_at: '', category: '', reason: '', rework_scope_authors: {} as Record<string, number> });
    const submitting = useRef(false);
    const selection = useRef(0);
    const [hashing, setHashing] = useState(false);
    async function select(file: File | null) {
        const token = ++selection.current;
        form.setData('file', file); form.setData('sha256', ''); form.clearErrors('file');
        if (!file) return;
        setHashing(true);
        try {
            const hash = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            if (token !== selection.current) return;
            form.setData('sha256', [...new Uint8Array(hash)].map(byte => byte.toString(16).padStart(2, '0')).join(''));
            form.setData('upload_key', crypto.randomUUID()); form.setData('command_key', crypto.randomUUID());
        } catch {
            if (token === selection.current) form.setError('file', 'File belum dapat diproses. Pilih ulang file melalui koneksi yang aman.');
        } finally { if (token === selection.current) setHashing(false); }
    }
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || hashing || submitting.current || !form.data.file || form.data.sha256.length !== 64 || !form.data.scope_confirmed || (decision && !form.data.decision)) return;
        if (decision && form.data.decision === 'rejected' && form.data.category === 'technical_rework'
            && (!Object.keys(form.data.rework_scope_authors).length || Object.values(form.data.rework_scope_authors).some(id => !id))) {
            form.setError('rework_scope_authors', 'Pilih scope defect dan penulis revisinya.'); return;
        }
        submitting.current = true;
        form.post(action.url, { forceFormData: true, preserveScroll: true, preserveState: 'errors', errorBag: `bast-evidence-${version.id}`, onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label={decision ? 'Rekam keputusan customer' : 'Terbitkan BAST'} className="panel flex min-w-0 flex-col gap-4">
        <h2 className="section-title">{decision ? 'Rekam keputusan customer' : 'Terbitkan BAST'}</h2>
        <p className="text-sm text-muted-foreground">{decision ? 'Unggah bukti keputusan eksternal untuk versi dan scope ini. Waktu keputusan customer dicatat terpisah dari waktu perekaman.' : 'Periksa isi dokumen yang diajukan dan kewenangan PIC atas seluruh scope/site dalam paket.'}</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing || hashing} className="grid min-w-0 gap-4 sm:grid-cols-2"><legend className="sr-only">Dokumen dan kewenangan PIC</legend>
            {decision && <FormField id="bast-decision" label="Keputusan customer *" error={form.errors.decision}>
                <select id="bast-decision" className="field-control" required value={form.data.decision} onChange={event => form.setData('decision', event.target.value)}><option value="">Pilih keputusan</option><option value="accepted">Diterima</option><option value="rejected">Ditolak</option></select>
            </FormField>}
            {([['signer_name', 'Nama PIC / penanda tangan', 150], ['signer_capacity', 'Jabatan / kapasitas', 150], ['authority_ref', 'Referensi bukti kewenangan untuk seluruh site', 180]] as const).map(([field, label, max]) => <FormField key={field} id={`bast-${field}`} label={`${label} *`} error={form.errors[field]}>
                <input id={`bast-${field}`} className="field-control" required maxLength={max} value={form.data[field]} onChange={event => form.setData(field, event.target.value)} />
            </FormField>)}
            <FormField id="bast-file" label={decision ? 'Bukti keputusan customer *' : 'Dokumen yang diajukan *'} error={form.errors.file} hint="PDF, JPG, PNG atau WebP, maksimal 10 MB.">
                <input id="bast-file" className="field-control h-auto py-2" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required onChange={event => { void select(event.target.files?.[0] ?? null); }} />
            </FormField>
            {decision && <FormField id="bast-decided-at" label="Waktu keputusan customer (WIB) *" error={form.errors.decided_at}>
                <input id="bast-decided-at" className="field-control" type="datetime-local" required value={form.data.decided_at.slice(0, 16)} onChange={event => form.setData('decided_at', withVisitOffset(event.target.value, 'Asia/Jakarta'))} />
            </FormField>}
            {decision && form.data.decision === 'rejected' && <>
                <FormField id="bast-reject-category" label="Tindak lanjut penolakan *" error={form.errors.category}><select id="bast-reject-category" className="field-control" required value={form.data.category} onChange={event => form.setData('category', event.target.value)}><option value="">Pilih tindak lanjut</option><option value="document_fix">Perbaikan dokumen BAST</option><option value="technical_rework">Rework teknis pada scope defect</option></select></FormField>
                <FormField id="bast-reject-reason" label="Alasan penolakan *" error={form.errors.reason}><textarea id="bast-reject-reason" className="field-control h-auto min-h-24 py-2" required maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></FormField>
            </>}
        </fieldset>
        {decision && form.data.decision === 'rejected' && form.data.category === 'technical_rework' && <fieldset disabled={form.processing} className="flex min-w-0 flex-col gap-3"><legend className="mb-3 font-medium">Scope defect dan penulis revisi</legend>
            {items.map(item => <div key={item.scope_id} className="flex flex-col gap-3 rounded-md border border-border p-3 text-sm">
                <label className="flex min-h-11 items-center gap-3"><input type="checkbox" className="size-4 accent-primary" checked={Object.hasOwn(form.data.rework_scope_authors, item.scope_id)} onChange={event => {
                    const next = { ...form.data.rework_scope_authors }; if (event.target.checked) next[item.scope_id] = 0; else delete next[item.scope_id]; form.setData('rework_scope_authors', next);
                }} /><span>{item.work_order_number} · {item.description}</span></label>
                {Object.hasOwn(form.data.rework_scope_authors, item.scope_id) && <FormField id={`bast-author-${item.scope_id}`} label="Penulis revisi *"><select id={`bast-author-${item.scope_id}`} className="field-control" required value={form.data.rework_scope_authors[item.scope_id] || ''} onChange={event => form.setData('rework_scope_authors', { ...form.data.rework_scope_authors, [item.scope_id]: Number(event.target.value) })}><option value="">Pilih teknisi anggota WO</option>{item.authors?.map(author => <option key={author.id} value={author.id}>{author.name}</option>)}</select></FormField>}
            </div>)}
        </fieldset>}
        <label className="flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" className="mt-1 size-4 accent-primary" required disabled={form.processing} checked={form.data.scope_confirmed} onChange={event => form.setData('scope_confirmed', event.target.checked)} /><span>Saya telah memeriksa bahwa file hanya memuat scope paket ini dan bukti kewenangan PIC mencakup seluruh site yang tercantum.</span></label>
        <Button type="submit" className="w-fit" disabled={form.processing || hashing || !form.data.file || form.data.sha256.length !== 64 || !form.data.scope_confirmed || (decision && !form.data.decision)} aria-busy={form.processing || hashing}>{hashing ? 'Memeriksa file…' : form.processing ? 'Menyimpan…' : decision ? 'Rekam keputusan dan bukti' : 'Terbitkan versi ini'}</Button>
    </form>;
}
