import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { BastScope } from '@/lib/acceptance';
import type { ReportAction } from '@/lib/report';

export function BastScopeForm({ action, candidates, initial = [], version, method = 'post', label, reasonRequired = false, refreshUrl }: {
    action: ReportAction; candidates: BastScope[]; initial?: BastScope[]; version?: number; method?: 'post' | 'patch'; label: string; reasonRequired?: boolean; refreshUrl: string;
}) {
    const options = [...new Map([...initial, ...candidates].map(item => [item.report_id, item])).values()];
    const form = useForm({ command_key: action.command_key, expected_version: version, report_ids: initial.map(item => item.report_id),
        work_order_versions: Object.fromEntries(options.map(item => [item.work_order_id, item.work_order_version])), reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current) return;
        submitting.current = true;
        form[method](action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `bast-scope-${action.command_key}`, onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label={label} className="panel flex min-w-0 flex-col gap-4">
        <h2 className="section-title">{label}</h2>
        <p className="text-sm text-muted-foreground">Pilih scope dari customer dan konteks acceptance yang sama. Satu scope memakai satu revisi laporan terbaru yang telah disetujui QA.</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="flex min-w-0 flex-col gap-3"><legend className="mb-2 font-medium">Scope dalam paket</legend>
            {options.map(item => <label key={item.report_id} className="flex min-h-11 items-start gap-3 rounded-md border border-border p-3 text-sm">
                <input type="checkbox" className="mt-1 size-4 accent-primary" value={item.report_id} checked={form.data.report_ids.includes(item.report_id)}
                    onChange={event => form.setData('report_ids', event.target.checked
                        ? [...form.data.report_ids.filter(id => !options.some(other => other.report_id === id && other.scope_id === item.scope_id)), item.report_id]
                        : form.data.report_ids.filter(id => id !== item.report_id))} />
                <span className="min-w-0 break-words"><span className="font-medium">{item.work_order_number} · Revisi {item.revision_no}</span><br />{item.description}{item.site_name ? ` · ${item.site_name}` : ''}</span>
            </label>)}
            {options.length === 0 && <p role="status" className="text-sm text-muted-foreground">Belum ada scope approved yang siap dimasukkan. Draft kosong dapat dilengkapi kemudian.</p>}
            {reasonRequired && <FormField id={`bast-revision-reason-${action.command_key}`} label="Alasan versi pengganti *" error={form.errors.reason}>
                <textarea id={`bast-revision-reason-${action.command_key}`} className="field-control h-auto min-h-24 py-2" required maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
            </FormField>}
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : label}</Button>
    </form>;
}
