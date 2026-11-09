import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { UserSummary } from '@/types';

type Props = {
    workOrder: {
        id: number;
        number: string;
        title: string;
        customer: string | null;
        site: string | null;
        status: string;
    };
    technicians: UserSummary[];
    links: { index: string; update: string };
};

export default function AssignWorkOrder({ workOrder, technicians, links }: Props) {
    const form = useForm<{ technician_ids: number[] }>({ technician_ids: [] });
    const error = Object.values(form.errors).filter(Boolean).join(' ');
    const cannotSubmit = form.processing || technicians.length === 0 || form.data.technician_ids.length === 0;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (cannotSubmit) {
            return;
        }

        form.patch(links.update);
    }

    return (
        <form onSubmit={submit} aria-label="Tugaskan Teknisi" className="panel flex w-full max-w-3xl flex-col gap-5">
            <dl className="grid min-w-0 gap-4 border-b border-border pb-5 sm:grid-cols-2">
                {[
                    ['Nomor WO', workOrder.number],
                    ['Pekerjaan', workOrder.title],
                    ['Customer', workOrder.customer ?? '—'],
                    ['Customer Site', workOrder.site ?? '—'],
                    ['Status', workOrder.status],
                ].map(([label, value]) => (
                    <div key={label} className="min-w-0">
                        <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
                        <dd className="mt-1 text-sm font-medium break-words">{value}</dd>
                    </div>
                ))}
            </dl>
            <FormField id="assignment-technicians" label="Teknisi *" error={error || undefined}>
                <select
                    id="assignment-technicians"
                    name="technician_ids"
                    className="field-control h-auto min-h-40 py-2"
                    multiple
                    required
                    size={6}
                    value={form.data.technician_ids.map(String)}
                    disabled={form.processing || technicians.length === 0}
                    aria-invalid={!!error}
                    aria-describedby={`assignment-technicians-hint${error ? ' assignment-technicians-error' : ''}`}
                    onChange={(event) => form.setData('technician_ids',
                        Array.from(event.target.selectedOptions, (option) => Number(option.value)))}
                >
                    {technicians.map((technician) => (
                        <option key={technician.id} value={technician.id}>{technician.name}</option>
                    ))}
                </select>
                <p id="assignment-technicians-hint" className="text-xs text-muted-foreground">
                    Pilih satu atau lebih teknisi. Di desktop, gunakan Ctrl atau Command untuk memilih beberapa teknisi.
                </p>
            </FormField>
            {technicians.length === 0 && (
                <p role="status" className="text-sm text-muted-foreground">
                    Belum ada teknisi aktif yang sudah disetujui. Hubungi Admin untuk menyiapkan akun teknisi.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" className="h-10 px-4 hover:bg-primary-hover" disabled={cannotSubmit} aria-busy={form.processing}>
                    {form.processing ? 'Menyimpan…' : 'Tugaskan Teknisi'}
                </Button>
                <Link href={links.index} className="text-sm underline underline-offset-4">Batal</Link>
            </div>
        </form>
    );
}

AssignWorkOrder.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Tugaskan Teknisi" description="Pilih teknisi untuk memulai penugasan Work Order.">
        {page}
    </AuthenticatedLayout>
);
