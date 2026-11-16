import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction, ReportRecord, ChecklistItem } from '@/lib/report';

type Props = { report: ReportRecord; action: ReportAction; assets: { id: number; asset_code: string }[]; refreshUrl: string };
export function ReportDraftForm({ report, action, assets, refreshUrl }: Props) {
    const form = useForm({ command_key: action.command_key, expected_report_version: report.lock_version,
        diagnosis: report.diagnosis ?? '', action_taken: report.action_taken ?? '', result: report.result ?? '', recommendation: report.recommendation ?? '',
        checklist_snapshot: report.checklist_snapshot ?? [], asset_results: report.asset_results ?? [] });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current) return;
        submitting.current = true;
        form.patch(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `edit-report-${report.id}`, onFinish: () => { submitting.current = false; } });
    }
    function checklist(index: number, field: keyof ChecklistItem, value: string) {
        form.setData('checklist_snapshot', form.data.checklist_snapshot.map((item, i) => i === index ? { ...item, [field]: value } : item));
    }
    return <form onSubmit={submit} aria-label="Edit draft laporan" className="panel flex flex-col gap-5">
        <h2 className="section-title">Isi laporan</h2>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="grid min-w-0 gap-5 md:grid-cols-2">
            <legend className="sr-only">Narasi hasil pekerjaan</legend>
            {([['diagnosis', 'Diagnosis'], ['action_taken', 'Tindakan'], ['result', 'Hasil'], ['recommendation', 'Rekomendasi (opsional)']] as const).map(([field, label]) => (
                <FormField key={field} id={`report-${field}`} label={label} error={form.errors[field]}>
                    <textarea id={`report-${field}`} name={field} className="field-control h-auto min-h-28 resize-y py-2" maxLength={10000}
                        value={form.data[field]} onChange={event => form.setData(field, event.target.value)} aria-invalid={!!form.errors[field]}
                        aria-describedby={form.errors[field] ? `report-${field}-error` : undefined} />
                </FormField>
            ))}
        </fieldset>
        <fieldset disabled={form.processing} className="flex min-w-0 flex-col gap-4">
            <legend className="mb-3 font-medium">Checklist hasil pemeriksaan</legend>
            {form.data.checklist_snapshot.map((item, index) => <div key={item.code} className="grid min-w-0 gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
                <FormField id={`check-${index}-label`} label={`Pemeriksaan ${index + 1}`}>
                    <input id={`check-${index}-label`} className="field-control" value={item.label} maxLength={255} onChange={event => checklist(index, 'label', event.target.value)} />
                </FormField>
                <FormField id={`check-${index}-outcome`} label="Hasil pemeriksaan">
                    <select id={`check-${index}-outcome`} className="field-control" value={item.outcome} onChange={event => checklist(index, 'outcome', event.target.value)}>
                        <option value="">Pilih hasil</option><option value="pass">Lulus</option><option value="fail">Tidak lulus</option><option value="not_applicable">Tidak relevan</option>
                    </select>
                </FormField>
                <FormField id={`check-${index}-notes`} label="Catatan (opsional)">
                    <input id={`check-${index}-notes`} className="field-control" maxLength={2000} value={item.notes ?? ''} onChange={event => checklist(index, 'notes', event.target.value)} />
                </FormField>
                <Button type="button" variant="outline" className="self-end" onClick={() => form.setData('checklist_snapshot', form.data.checklist_snapshot.filter((_, i) => i !== index))}>Hapus pemeriksaan {index + 1}</Button>
            </div>)}
            <Button type="button" variant="outline" className="w-fit" disabled={form.data.checklist_snapshot.length >= 100}
                onClick={() => form.setData('checklist_snapshot', [...form.data.checklist_snapshot, { code: crypto.randomUUID(), label: '', outcome: '', notes: '' }])}>Tambah pemeriksaan</Button>
        </fieldset>
        <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend className="mb-3 font-medium">Hasil per aset (bila relevan)</legend>
            {assets.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada aset tertaut pada WO ini.</p>}
            {assets.map(asset => <FormField key={asset.id} id={`asset-result-${asset.id}`} label={asset.asset_code}>
                <textarea id={`asset-result-${asset.id}`} className="field-control h-auto min-h-20 py-2" maxLength={2000}
                    value={form.data.asset_results.find(item => item.asset_id === asset.id)?.result ?? ''}
                    onChange={event => form.setData('asset_results', [...form.data.asset_results.filter(item => item.asset_id !== asset.id),
                        ...(event.target.value ? [{ asset_id: asset.id, result: event.target.value }] : [])])} />
            </FormField>)}
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Simpan draft'}</Button>
    </form>;
}
