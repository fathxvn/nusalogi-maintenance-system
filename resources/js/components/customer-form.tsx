import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import type { CustomerFormData, CustomerRecord } from '@/types';

type Props = {
    customer?: CustomerRecord;
    action: string;
    method: 'post' | 'patch';
    cancelHref: string;
    submitLabel: string;
};

const fields = [
    { name: 'name', label: 'Nama Customer', type: 'text', maxLength: 150, autoComplete: 'organization' },
    { name: 'pic_name', label: 'Nama PIC', type: 'text', maxLength: 150, autoComplete: 'name' },
    { name: 'phone', label: 'Telepon', type: 'tel', maxLength: 30, autoComplete: 'tel' },
    { name: 'email', label: 'Email', type: 'email', maxLength: 150, autoComplete: 'email' },
] as const;

export function CustomerForm({ customer, action, method, cancelHref, submitLabel }: Props) {
    const form = useForm<CustomerFormData>({
        name: customer?.name ?? '',
        pic_name: customer?.pic_name ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        billing_address: customer?.billing_address ?? '',
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
            {fields.map((field) => {
                const id = `customer-${field.name}`;

                return (
                    <FormField key={field.name} id={id} label={field.label} error={form.errors[field.name]}>
                        <input
                            id={id}
                            name={field.name}
                            type={field.type}
                            className="field-control"
                            required={field.name === 'name'}
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
            <FormField
                id="customer-billing-address"
                label="Alamat Penagihan"
                error={form.errors.billing_address}
            >
                <textarea
                    id="customer-billing-address"
                    name="billing_address"
                    className="field-control h-auto min-h-28 resize-y py-2"
                    maxLength={65535}
                    autoComplete="street-address"
                    value={form.data.billing_address}
                    aria-invalid={!!form.errors.billing_address}
                    aria-describedby={form.errors.billing_address ? 'customer-billing-address-error' : undefined}
                    onChange={(event) => form.setData('billing_address', event.target.value)}
                />
            </FormField>
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button
                    type="submit"
                    className="h-10 px-4 hover:bg-primary-hover"
                    disabled={form.processing}
                    aria-busy={form.processing}
                >
                    {form.processing ? 'Menyimpan…' : submitLabel}
                </Button>
                <Link href={cancelHref} className="text-sm underline underline-offset-4">Batal</Link>
            </div>
        </form>
    );
}
