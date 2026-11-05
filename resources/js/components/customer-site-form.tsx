import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import type { CustomerRecord, CustomerSiteFormData, CustomerSiteRecord } from '@/types';

type Props = {
    customer: Pick<CustomerRecord, 'id' | 'name'>;
    site?: CustomerSiteRecord;
    action: string;
    method: 'post' | 'patch';
    cancelHref: string;
    submitLabel: string;
};

const fields = [
    { name: 'site_name', label: 'Nama Site', type: 'text', maxLength: 150, autoComplete: 'off' },
    { name: 'pic_name', label: 'Nama PIC', type: 'text', maxLength: 150, autoComplete: 'name' },
    { name: 'pic_phone', label: 'Telepon PIC', type: 'tel', maxLength: 30, autoComplete: 'tel' },
] as const;

export function CustomerSiteForm({ customer, site, action, method, cancelHref, submitLabel }: Props) {
    const form = useForm<CustomerSiteFormData>({
        site_name: site?.site_name ?? '',
        address: site?.address ?? '',
        pic_name: site?.pic_name ?? '',
        pic_phone: site?.pic_phone ?? '',
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
        <form onSubmit={submit} aria-label={submitLabel} className="panel flex w-full max-w-2xl flex-col gap-5">
            <dl className="min-w-0 border-b border-border pb-5">
                <dt className="text-xs font-medium text-muted-foreground">Customer</dt>
                <dd className="mt-1 text-sm font-medium break-words">{customer.name}</dd>
            </dl>
            {fields.map((field) => {
                const id = `customer-site-${field.name}`;

                return (
                    <FormField key={field.name} id={id} label={field.label} error={form.errors[field.name]}>
                        <input
                            id={id}
                            name={field.name}
                            type={field.type}
                            className="field-control"
                            required={field.name === 'site_name'}
                            maxLength={field.maxLength}
                            autoComplete={field.autoComplete}
                            value={form.data[field.name]}
                            aria-invalid={!!form.errors[field.name]}
                            aria-describedby={form.errors[field.name] ? `${id}-error` : undefined}
                            onChange={(event) => form.setData(field.name, event.target.value)}
                        />
                    </FormField>
                );
            })}
            <FormField id="customer-site-address" label="Alamat Site" error={form.errors.address}>
                <textarea
                    id="customer-site-address"
                    name="address"
                    className="field-control h-auto min-h-28 resize-y py-2"
                    required
                    maxLength={65535}
                    autoComplete="street-address"
                    value={form.data.address}
                    aria-invalid={!!form.errors.address}
                    aria-describedby={form.errors.address ? 'customer-site-address-error' : undefined}
                    onChange={(event) => form.setData('address', event.target.value)}
                />
            </FormField>
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" className="h-10 px-4 hover:bg-primary-hover" disabled={form.processing} aria-busy={form.processing}>
                    {form.processing ? 'Menyimpan…' : submitLabel}
                </Button>
                <Link href={cancelHref} className="text-sm underline underline-offset-4">Batal</Link>
            </div>
        </form>
    );
}
