import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { WorkOrderFormData, WorkOrderSiteOption } from '@/types';

type Props = {
    m1?: { command_key: string; ticket_id: number | null; ticket_version: number | null; site_id: number | null; ticket_number: string | null } | null;
    m1CreateUrl?: string;
    sites: WorkOrderSiteOption[];
    serviceTypes: Record<string, string>;
    priorities: Record<string, string>;
    scheduleTimezone: string;
    links: { index: string; store: string };
};

export default function CreateWorkOrder({ sites, serviceTypes, priorities, scheduleTimezone, links, m1, m1CreateUrl }: Props) {
    const form = useForm<WorkOrderFormData>({
        customer_site_id: m1?.site_id?.toString() ?? '', title: '', description: '', service_type: '',
        category: '', priority: 'medium', scheduled_at: '', asset_ids: [],
        ...(m1 ? {
            command_key: m1.command_key,
            origin_kind: m1.ticket_id ? 'ticket' as const : 'manual' as const,
            ticket_id: m1.ticket_id,
            expected_ticket_version: m1.ticket_version,
            manual_reason: '',
            initial_scope: { description: '', acceptance_criteria: '' },
        } : {}),
    });
    const site = sites.find((option) => option.id.toString() === form.data.customer_site_id);
    const assetError = Object.entries(form.errors)
        .filter(([field]) => field === 'asset_ids' || field.startsWith('asset_ids.'))
        .map(([, error]) => error).join(' ');
    const scopeErrors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (form.processing || sites.length === 0) {
            return;
        }

        form.post(links.store);
    }

    return (
        <form onSubmit={submit} aria-label="Tambah Work Order" className="panel flex w-full max-w-3xl flex-col gap-5">
            <p className="text-sm text-muted-foreground">Kolom bertanda * wajib diisi.</p>
            {!m1 && m1CreateUrl && (
                <Link href={m1CreateUrl} className="underline">Buat WO manual dengan scope dan alasan</Link>
            )}
            {m1 && (
                <section className="flex flex-col gap-4" aria-label="Asal dan scope pekerjaan">
                    <p>{m1.ticket_id ? `Asal ticket: ${m1.ticket_number}` : 'Asal: manual'}</p>
                    {!m1.ticket_id && (
                        <FormField id="manual-reason" label="Alasan manual *" error={form.errors.manual_reason}>
                            <textarea
                                id="manual-reason" name="manual_reason" className="field-control"
                                required maxLength={10000} value={form.data.manual_reason ?? ''}
                                onChange={event => form.setData('manual_reason', event.target.value)}
                            />
                        </FormField>
                    )}
                    <FormField
                        id="scope-description" label="Hasil kerja / scope awal *"
                        error={scopeErrors['initial_scope.description']}
                    >
                        <textarea
                            id="scope-description" name="initial_scope.description"
                            required maxLength={10000} className="field-control"
                            value={form.data.initial_scope?.description ?? ''}
                            onChange={event => form.setData('initial_scope', {
                                description: event.target.value,
                                acceptance_criteria: form.data.initial_scope?.acceptance_criteria ?? '',
                            })}
                        />
                    </FormField>
                    <FormField
                        id="scope-criteria" label="Kriteria penerimaan *"
                        error={scopeErrors['initial_scope.acceptance_criteria']}
                    >
                        <textarea
                            id="scope-criteria" name="initial_scope.acceptance_criteria"
                            required maxLength={10000} className="field-control"
                            value={form.data.initial_scope?.acceptance_criteria ?? ''}
                            onChange={event => form.setData('initial_scope', {
                                description: form.data.initial_scope?.description ?? '',
                                acceptance_criteria: event.target.value,
                            })}
                        />
                    </FormField>
                    {['command_key', 'ticket_id', 'expected_ticket_version', 'origin_kind'].map(field => (
                        scopeErrors[field] && (
                            <p key={field} role="alert">{scopeErrors[field]} · Muat ulang formulir bila versi berubah.</p>
                        )
                    ))}
                </section>
            )}
            <FormField id="work-order-site" label="Customer dan Site *" error={form.errors.customer_site_id}>
                <select
                    id="work-order-site" name="customer_site_id" className="field-control" required
                    disabled={!!m1?.ticket_id}
                    value={form.data.customer_site_id}
                    aria-invalid={!!form.errors.customer_site_id}
                    aria-describedby={form.errors.customer_site_id ? 'work-order-site-error' : undefined}
                    onChange={(event) => {
                        form.setData('customer_site_id', event.target.value);
                        form.setData('asset_ids', []);
                    }}
                >
                    <option value="">Pilih lokasi pekerjaan</option>
                    {sites.map((option) => (
                        <option key={option.id} value={option.id}>{option.customer_name ?? '—'} — {option.site_name}</option>
                    ))}
                </select>
            </FormField>
            {sites.length === 0 && (
                <p role="status" className="text-sm text-muted-foreground">
                    Belum ada Customer Site. Hubungi Admin untuk menambahkan lokasi pekerjaan.
                </p>
            )}
            <FormField id="work-order-title" label="Judul Pekerjaan *" error={form.errors.title}>
                <input
                    id="work-order-title" name="title" className="field-control" required maxLength={150}
                    value={form.data.title} aria-invalid={!!form.errors.title}
                    aria-describedby={form.errors.title ? 'work-order-title-error' : undefined}
                    onChange={(event) => form.setData('title', event.target.value)}
                />
            </FormField>
            <FormField id="work-order-description" label="Deskripsi / Keluhan *" error={form.errors.description}>
                <textarea
                    id="work-order-description" name="description" className="field-control h-auto min-h-28 resize-y py-2"
                    required maxLength={65535} value={form.data.description} aria-invalid={!!form.errors.description}
                    aria-describedby={form.errors.description ? 'work-order-description-error' : undefined}
                    onChange={(event) => form.setData('description', event.target.value)}
                />
            </FormField>
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField id="work-order-service" label="Jenis Layanan *" error={form.errors.service_type}>
                    <select
                        id="work-order-service" name="service_type" className="field-control" required
                        value={form.data.service_type} aria-invalid={!!form.errors.service_type}
                        aria-describedby={form.errors.service_type ? 'work-order-service-error' : undefined}
                        onChange={(event) => form.setData('service_type', event.target.value)}
                    >
                        <option value="">Pilih jenis layanan</option>
                        {Object.entries(serviceTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </FormField>
                <FormField id="work-order-category" label="Kategori *" error={form.errors.category}>
                    <input
                        id="work-order-category" name="category" className="field-control" required maxLength={50}
                        value={form.data.category} aria-invalid={!!form.errors.category}
                        aria-describedby={form.errors.category ? 'work-order-category-error' : undefined}
                        onChange={(event) => form.setData('category', event.target.value)}
                    />
                </FormField>
                <FormField id="work-order-priority" label="Prioritas *" error={form.errors.priority}>
                    <select
                        id="work-order-priority" name="priority" className="field-control" required
                        value={form.data.priority} aria-invalid={!!form.errors.priority}
                        aria-describedby={form.errors.priority ? 'work-order-priority-error' : undefined}
                        onChange={(event) => form.setData('priority', event.target.value)}
                    >
                        {Object.entries(priorities).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </FormField>
                <FormField id="work-order-schedule" label={`Jadwal (${scheduleTimezone})`} error={form.errors.scheduled_at}>
                    <input
                        id="work-order-schedule" name="scheduled_at" type="datetime-local" step={60} className="field-control"
                        value={form.data.scheduled_at} aria-invalid={!!form.errors.scheduled_at}
                        aria-describedby={form.errors.scheduled_at ? 'work-order-schedule-error' : undefined}
                        onChange={(event) => form.setData('scheduled_at', event.target.value)}
                    />
                </FormField>
            </div>
            <fieldset
                id="work-order-assets" className="flex min-w-0 flex-col gap-3"
                aria-invalid={!!assetError}
                aria-describedby={`work-order-assets-hint${assetError ? ' work-order-assets-error' : ''}`}
            >
                <legend className="mb-2 text-sm font-medium">Asset (opsional)</legend>
                <p id="work-order-assets-hint" className="text-xs text-muted-foreground">
                    Pilih asset dari lokasi pekerjaan. Pekerjaan instalasi dapat dibuat tanpa asset.
                </p>
                {!site ? (
                    <p className="text-sm text-muted-foreground">Pilih Customer Site terlebih dahulu.</p>
                ) : site.assets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Belum ada asset di lokasi ini. Work Order tetap dapat disimpan.</p>
                ) : (
                    <div className="flex max-h-64 flex-col gap-2 overflow-y-auto">
                        {site.assets.map((asset) => (
                            <label key={asset.id} className="flex min-h-11 items-center gap-3 rounded-md border border-border px-3 py-2 text-sm">
                                <input
                                    name="asset_ids[]" type="checkbox" value={asset.id}
                                    className="size-4 shrink-0 accent-primary" disabled={form.processing}
                                    checked={form.data.asset_ids.includes(asset.id)}
                                    onChange={(event) => form.setData('asset_ids', event.target.checked
                                        ? [...form.data.asset_ids, asset.id]
                                        : form.data.asset_ids.filter((id) => id !== asset.id))}
                                />
                                <span className="min-w-0 break-words">{asset.asset_code} — {asset.category}</span>
                            </label>
                        ))}
                    </div>
                )}
                {assetError && <p id="work-order-assets-error" role="alert" className="text-sm text-destructive">{assetError}</p>}
            </fieldset>
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" className="h-10 px-4 hover:bg-primary-hover" disabled={form.processing || sites.length === 0} aria-busy={form.processing}>
                    {form.processing ? 'Menyimpan…' : 'Simpan Work Order'}
                </Button>
                <Link href={links.index} className="text-sm underline underline-offset-4">Batal</Link>
            </div>
        </form>
    );
}

CreateWorkOrder.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Tambah Work Order" description="Catat pekerjaan baru dan lokasi pelaksanaannya.">
        {page}
    </AuthenticatedLayout>
);
