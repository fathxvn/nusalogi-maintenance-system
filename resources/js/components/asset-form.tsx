import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import type {
    AssetFormData,
    AssetRecord,
    AssetSiteOption,
} from '@/types';

type Props = {
    sites: AssetSiteOption[];
    asset?: AssetRecord;
    action: string;
    method: 'post' | 'patch';
    cancelHref: string;
    submitLabel: string;
};

const fields = [
    {
        name: 'asset_code',
        label: 'Kode Asset',
        type: 'text',
        maxLength: 50,
        required: true,
    },
    {
        name: 'category',
        label: 'Kategori',
        type: 'text',
        maxLength: 100,
        required: true,
    },
    {
        name: 'brand',
        label: 'Merek',
        type: 'text',
        maxLength: 100,
        required: false,
    },
    {
        name: 'model',
        label: 'Model',
        type: 'text',
        maxLength: 100,
        required: false,
    },
    {
        name: 'serial_number',
        label: 'Nomor Seri',
        type: 'text',
        maxLength: 150,
        required: false,
    },
    {
        name: 'installed_at',
        label: 'Tanggal Pemasangan',
        type: 'date',
        maxLength: undefined,
        required: false,
    },
] as const;

export function AssetForm({
    sites,
    asset,
    action,
    method,
    cancelHref,
    submitLabel,
}: Props) {
    const form = useForm<AssetFormData>({
        customer_site_id: asset?.site.id?.toString() ?? '',
        asset_code: asset?.asset_code ?? '',
        category: asset?.category ?? '',
        brand: asset?.brand ?? '',
        model: asset?.model ?? '',
        serial_number: asset?.serial_number ?? '',
        installed_at: asset?.installed_at ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (method === 'post') {
            form.post(action);
        } else {
            form.patch(action);
        }
    }

    return (
        <form
            onSubmit={submit}
            aria-label={submitLabel}
            className="panel flex w-full max-w-2xl flex-col gap-5"
        >
            <FormField
                id="asset-site"
                label="Customer dan Site"
                error={form.errors.customer_site_id}
            >
                <select
                    id="asset-site"
                    name="customer_site_id"
                    className="field-control"
                    required
                    value={form.data.customer_site_id}
                    aria-invalid={!!form.errors.customer_site_id}
                    aria-describedby={
                        form.errors.customer_site_id
                            ? 'asset-site-error'
                            : undefined
                    }
                    onChange={(event) =>
                        form.setData('customer_site_id', event.target.value)
                    }
                >
                    <option value="">Pilih lokasi perangkat</option>

                    {sites.map((site) => (
                        <option key={site.id} value={site.id}>
                            {site.customer_name ?? '—'} — {site.site_name}
                        </option>
                    ))}
                </select>
            </FormField>

            {sites.length === 0 && (
                <p role="status" className="text-sm text-muted-foreground">
                    Tambahkan Customer Site terlebih dahulu melalui
                    halaman Detail Customer.
                </p>
            )}

            {fields.map((field) => {
                const id = `asset-${field.name}`;

                return (
                    <FormField
                        key={field.name}
                        id={id}
                        label={field.label}
                        error={form.errors[field.name]}
                    >
                        <input
                            id={id}
                            name={field.name}
                            type={field.type}
                            className="field-control"
                            maxLength={field.maxLength}
                            required={field.required}
                            value={form.data[field.name]}
                            aria-invalid={!!form.errors[field.name]}
                            aria-describedby={
                                form.errors[field.name]
                                    ? `${id}-error`
                                    : undefined
                            }
                            onChange={(event) =>
                                form.setData(field.name, event.target.value)
                            }
                        />
                    </FormField>
                );
            })}

            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button
                    type="submit"
                    className="h-10 px-4 hover:bg-primary-hover"
                    disabled={form.processing || sites.length === 0}
                    aria-busy={form.processing}
                >
                    {form.processing ? 'Menyimpan…' : submitLabel}
                </Button>

                <Link
                    href={cancelHref}
                    className="text-sm underline underline-offset-4"
                >
                    Batal
                </Link>
            </div>
        </form>
    );
}
