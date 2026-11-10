import { Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';

type Props = {
    ticket: {
        id: number;
        ticket_number: string;
        contact_label: string;
        description: string;
        status: string;
        site_id: number | null;
        primary_asset_id: number | null;
        coverage_disposition: string | null;
        authorization_ref: string | null;
        lock_version: number;
    };
    sites: {
        id: number;
        site_name: string;
        assets: { id: number; asset_code: string }[];
    }[];
    commandKeys: { triage: string; approve: string };
    links: {
        index: string;
        triage: string | null;
        approve: string | null;
        createWorkOrder: string | null;
    };
    workOrders: { id: number; wo_number: string; status: string }[];
};

export default function ShowTicket(props: Props) {
    // Successful mutations replace the forms with the new version and command keys.
    // Failed-request retries keep the same form instance and command key.
    return <TicketDetails key={`${props.ticket.id}:${props.ticket.lock_version}`} {...props} />;
}

function TicketDetails({ ticket, sites, commandKeys, links, workOrders }: Props) {
    const triage = useForm({
        command_key: commandKeys.triage,
        expected_ticket_version: ticket.lock_version,
        site_id: ticket.site_id?.toString() ?? '',
        primary_asset_id: ticket.primary_asset_id?.toString() ?? '',
        coverage_disposition: ticket.coverage_disposition ?? 'unknown',
        authorization_ref: ticket.authorization_ref ?? '',
    });
    const approve = useForm({
        command_key: commandKeys.approve,
        expected_ticket_version: ticket.lock_version,
    });
    const site = sites.find(option => option.id.toString() === triage.data.site_id);

    return (
        <div className="flex max-w-3xl flex-col gap-5">
            <section className="panel flex flex-col gap-3">
                <h2>{ticket.ticket_number} · {ticket.status}</h2>
                <p>{ticket.contact_label}</p>
                <p className="whitespace-pre-wrap">{ticket.description}</p>
                <p>Coverage {ticket.coverage_disposition ?? 'unknown'} · Referensi {ticket.authorization_ref ?? '—'}</p>
            </section>

            {links.triage && (
                <form
                    className="panel flex flex-col gap-4"
                    aria-label="Triage ticket"
                    onSubmit={event => {
                        event.preventDefault();
                        if (!triage.processing) {
                            triage.post(links.triage!);
                        }
                    }}
                >
                    <FormField id="triage-site" label="Site *" error={triage.errors.site_id}>
                        <select
                            id="triage-site"
                            required
                            className="field-control"
                            value={triage.data.site_id}
                            onChange={event => {
                                triage.setData('site_id', event.target.value);
                                triage.setData('primary_asset_id', '');
                            }}
                        >
                            <option value="">Pilih site</option>
                            {sites.map(option => (
                                <option key={option.id} value={option.id}>{option.site_name} (#{option.id})</option>
                            ))}
                        </select>
                    </FormField>
                    <FormField id="triage-asset" label="Asset (opsional)" error={triage.errors.primary_asset_id}>
                        <select
                            id="triage-asset"
                            className="field-control"
                            value={triage.data.primary_asset_id}
                            onChange={event => triage.setData('primary_asset_id', event.target.value)}
                        >
                            <option value="">Tanpa asset utama</option>
                            {site?.assets.map(asset => (
                                <option key={asset.id} value={asset.id}>{asset.asset_code}</option>
                            ))}
                        </select>
                    </FormField>
                    <FormField id="coverage" label="Coverage *" error={triage.errors.coverage_disposition}>
                        <select
                            id="coverage"
                            className="field-control"
                            value={triage.data.coverage_disposition}
                            onChange={event => triage.setData('coverage_disposition', event.target.value)}
                        >
                            {['unknown', 'covered', 'no_charge', 'authorized'].map(value => (
                                <option key={value}>{value}</option>
                            ))}
                        </select>
                    </FormField>
                    <FormField
                        id="authorization"
                        label="Referensi otorisasi biaya (wajib untuk authorized)"
                        error={triage.errors.authorization_ref}
                    >
                        <input
                            id="authorization"
                            maxLength={180}
                            className="field-control"
                            value={triage.data.authorization_ref}
                            onChange={event => triage.setData('authorization_ref', event.target.value)}
                        />
                    </FormField>
                    {Object.entries(triage.errors)
                        .filter(([field]) => ['expected_ticket_version', 'ticket_id', 'command_key'].includes(field))
                        .map(([field, message]) => (
                            <p key={field} role="alert">{message} · Muat ulang untuk data terbaru.</p>
                        ))}
                    <Button type="submit" disabled={triage.processing}>Simpan Triage</Button>
                </form>
            )}

            {links.approve && (
                <form
                    className="panel flex flex-col gap-3"
                    aria-label="Approval ticket"
                    onSubmit={event => {
                        event.preventDefault();
                        if (!approve.processing) {
                            approve.post(links.approve!);
                        }
                    }}
                >
                    <p>Catat persetujuan operasional berdasarkan coverage dan referensi biaya dari pihak berwenang.</p>
                    {Object.entries(approve.errors).map(([field, message]) => (
                        <p key={field} role="alert">{message} · Muat ulang bila versi berubah.</p>
                    ))}
                    <Button type="submit" disabled={approve.processing}>Setujui Operasional</Button>
                </form>
            )}

            {links.createWorkOrder && (
                <Link className="underline" href={links.createWorkOrder}>Buat Work Order dari Ticket</Link>
            )}
            <section className="panel">
                <h2>Work Order terkait ({workOrders.length})</h2>
                {workOrders.map(workOrder => <p key={workOrder.id}>{workOrder.wo_number} · {workOrder.status}</p>)}
            </section>
            <Link href={links.index}>Kembali ke Ticket</Link>
        </div>
    );
}

ShowTicket.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Detail Ticket" description="Triage, persetujuan, dan tindak lanjut pekerjaan.">
        {page}
    </AuthenticatedLayout>
);
