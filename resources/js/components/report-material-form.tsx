import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportFormErrors } from '@/components/report-form-errors';
import { Button } from '@/components/ui/button';
import type { ReportAction, MaterialMovement } from '@/lib/report';

export function ReportMaterialForm({ action, reportVersion, movements, refreshUrl }: { action: ReportAction; reportVersion: number; movements: MaterialMovement[]; refreshUrl: string }) {
    const form = useForm({ command_key: action.command_key, expected_report_version: reportVersion, movement_kind: 'consume',
        adjusts_usage_id: null as number | null, item_description: '', unit_code: '', quantity: '', supply_source: 'unknown', disposition: 'pending', reason: '' });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current) return;
        submitting.current = true;
        form.post(action.url, { preserveScroll: true, errorBag: 'report-material', onFinish: () => { submitting.current = false; } });
    }
    function parent(id: string) {
        const movement = movements.find(item => item.id === Number(id));
        form.setData('adjusts_usage_id', movement?.id ?? null);
        if (movement) {
            for (const key of ['item_description', 'unit_code', 'supply_source', 'disposition'] as const) form.setData(key, movement[key]);
        }
    }
    return <form onSubmit={submit} aria-label="Catat pemakaian material" className="panel flex flex-col gap-4">
        <h2 className="section-title">Catat material</h2>
        <p className="text-sm text-muted-foreground">Koreksi atau pengembalian menambah catatan baru dan mempertahankan pemakaian asal.</p>
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-2">
            <legend className="sr-only">Material dan jumlah</legend>
            <FormField id="material-kind" label="Jenis catatan *" error={form.errors.movement_kind}>
                <select id="material-kind" className="field-control" value={form.data.movement_kind} onChange={event => { form.setData('movement_kind', event.target.value); form.setData('adjusts_usage_id', null); }}>
                    <option value="consume">Pemakaian</option><option value="return">Pengembalian</option><option value="reversal">Reversal / koreksi</option>
                </select>
            </FormField>
            {form.data.movement_kind !== 'consume' && <FormField id="material-parent" label="Pemakaian asal *" error={form.errors.adjusts_usage_id}>
                <select id="material-parent" className="field-control" required value={form.data.adjusts_usage_id ?? ''} onChange={event => parent(event.target.value)}>
                    <option value="">Pilih pemakaian</option>{movements.filter(item => item.movement_kind === 'consume').map(item => <option key={item.id} value={item.id}>#{item.id} · {item.item_description} · {item.quantity} {item.unit_code}</option>)}
                </select>
            </FormField>}
            {([['item_description', 'Material', 180], ['unit_code', 'Satuan', 24], ['quantity', 'Jumlah (maks. 3 desimal)', 16]] as const).map(([field, label, length]) => <FormField key={field} id={`material-${field}`} label={`${label} *`} error={form.errors[field]}>
                <input id={`material-${field}`} className="field-control" required maxLength={length} inputMode={field === 'quantity' ? 'decimal' : undefined}
                    value={form.data[field]} onChange={event => form.setData(field, event.target.value)} readOnly={form.data.movement_kind !== 'consume' && field !== 'quantity'} />
            </FormField>)}
            <FormField id="material-source" label="Sumber material *" error={form.errors.supply_source}>
                <select id="material-source" className="field-control" value={form.data.supply_source} disabled={form.data.movement_kind !== 'consume'} onChange={event => form.setData('supply_source', event.target.value)}>
                    <option value="unknown">Belum diketahui</option><option value="company">Perusahaan</option><option value="customer">Customer</option>
                </select>
            </FormField>
            <FormField id="material-disposition" label="Disposition operasional *" error={form.errors.disposition}>
                <select id="material-disposition" className="field-control" value={form.data.disposition} disabled={form.data.movement_kind !== 'consume'} onChange={event => form.setData('disposition', event.target.value)}>
                    <option value="pending">Menunggu keputusan</option><option value="covered">Covered</option><option value="customer_supplied">Disediakan customer</option><option value="authorized_extra">Tambahan berotorisasi</option>
                </select>
            </FormField>
            <FormField id="material-reason" label={form.data.movement_kind === 'consume' ? 'Catatan (opsional)' : 'Alasan koreksi / pengembalian *'} error={form.errors.reason}>
                <textarea id="material-reason" className="field-control h-auto min-h-20 py-2" required={form.data.movement_kind !== 'consume'} maxLength={10000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
            </FormField>
        </fieldset>
        <Button type="submit" className="w-fit" disabled={form.processing} aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Catat material'}</Button>
    </form>;
}
