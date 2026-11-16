import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction } from '@/lib/report';
export type LifecycleAction = 'hold' | 'resume' | 'cancel' | 'context' | 'close';
const labels: Record<LifecycleAction, string> = { hold: 'Tahan WO', resume: 'Lanjutkan WO', cancel: 'Batalkan WO sebelum eksekusi', context: 'Tetapkan konteks acceptance', close: 'Tutup WO berdasarkan acceptance' };
export function WorkOrderLifecycleForm({ action, kind, version, context, refreshUrl, disabled = false }: { action: ReportAction; kind: LifecycleAction; version: number; context: string | null; refreshUrl: string; disabled?: boolean }) {
    const form = useForm({ command_key: action.command_key, expected_work_order_version: version, reason: '', ...(kind === 'context' ? { acceptance_context_ref: context ?? '' } : {}) });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current || disabled || (kind !== 'close' && !form.data.reason.trim())) return;
        submitting.current = true;
        form.post(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `lifecycle-${kind}`, onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label={labels[kind]} className="panel flex min-w-0 flex-col gap-4"><h2 className="section-title">{labels[kind]}</h2>
        {kind === 'close' && <p className="text-sm text-muted-foreground">{disabled ? 'Lengkapi acceptance semua scope wajib aktif sebelum menutup WO.' : 'Bukti acceptance seluruh scope wajib diperiksa kembali saat penutupan.'}</p>}
        {kind === 'cancel' && <p className="text-sm text-muted-foreground">Pembatalan normal menangani scope dan booking mendatang sambil mempertahankan histori.</p>}
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing || disabled} className="flex min-w-0 flex-col gap-4"><legend className="sr-only">Keputusan lifecycle</legend>
            {kind === 'context' && <FormField id="wo-acceptance-context" label="Referensi konteks acceptance bersama *" error={form.errors.acceptance_context_ref}><input id="wo-acceptance-context" required className="field-control" maxLength={150} value={form.data.acceptance_context_ref ?? ''} onChange={event => form.setData('acceptance_context_ref', event.target.value)} /></FormField>}
            <FormField id={`wo-${kind}-reason`} label={kind === 'close' ? 'Catatan penutupan (opsional)' : 'Alasan / keputusan *'} error={form.errors.reason}><textarea id={`wo-${kind}-reason`} required={kind !== 'close'} className="field-control h-auto min-h-24 py-2" maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></FormField>
        </fieldset>
        <Button type="submit" className="w-fit" variant={kind === 'cancel' ? 'outline' : 'default'} disabled={form.processing || disabled} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : labels[kind]}</Button>
    </form>;
}
