import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { VisitFormErrors } from '@/components/visit-form-errors';
import { visitInputValue, visitZones, withVisitOffset } from '@/lib/visit-time';
import type { UserSummary, VisitRecord } from '@/types';

type Props = { visit: VisitRecord; technicians: UserSummary[]; refreshUrl: string };

export function VisitRescheduleAction({ visit, technicians, refreshUrl }: Props) {
    const [open, setOpen] = useState(false);
    const submitting = useRef(false);
    const form = useForm({
        command_key: visit.reschedule_command_key ?? '', expected_visit_version: visit.lock_version,
        starts_at: visitInputValue(visit.starts_at, visit.zone_name), ends_at: visitInputValue(visit.ends_at, visit.zone_name),
        zone_name: visit.zone_name,
        technician_ids: visit.active_technician_ids.filter(id => technicians.some(technician => technician.id === id)),
        reason: '',
    });
    const zones = visitZones.some(zone => zone.value === visit.zone_name)
        ? [...visitZones] : [...visitZones, { value: visit.zone_name, label: visit.zone_name }];
    const participantErrors = Object.entries(form.errors)
        .filter(([key]) => key === 'technician_ids' || key.startsWith('technician_ids.')).map(([, value]) => value).join(' ');
    const prefix = `reschedule-${visit.id}`;
    const hasUnavailableSelection = form.data.technician_ids.some(id => !technicians.some(technician => technician.id === id));

    function changeOpen(next: boolean) {
        if (form.processing || submitting.current) return;
        setOpen(next);
    }

    function changeTime(field: 'starts_at' | 'ends_at', local: string, zone = form.data.zone_name) {
        try {
            form.setData(field, withVisitOffset(local, zone));
            form.clearErrors(field);
        } catch (error) {
            form.setData(field, local);
            form.setError(field, error instanceof Error ? error.message : 'Waktu tidak valid.');
        }
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger render={<Button type="button" variant="outline" className="mt-4 min-h-10" disabled={form.processing} />}
                aria-label={`Jadwal ulang kunjungan ${visit.visit_no}`}>Jadwal ulang</DialogTrigger>
            <DialogContent showCloseButton={!form.processing} className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-2xl">
                <form className="flex min-w-0 flex-col gap-5" aria-label={`Jadwal ulang kunjungan ${visit.visit_no}`} onSubmit={event => {
                    event.preventDefault();
                    if (!visit.reschedule_url || !visit.reschedule_command_key || form.processing || submitting.current
                        || form.data.technician_ids.length === 0 || form.data.reason.trim() === '') return;
                    if (!form.data.starts_at || !form.data.ends_at) return;
                    // Offsetless input is retained for correction when an IANA wall time is invalid/ambiguous.
                    if (!/[+-]\d{2}:\d{2}$/.test(form.data.starts_at) || !/[+-]\d{2}:\d{2}$/.test(form.data.ends_at)) return;
                    submitting.current = true;
                    form.post(visit.reschedule_url, {
                        preserveScroll: true, errorBag: `reschedule-visit-${visit.id}`,
                        onSuccess: () => setOpen(false), onFinish: () => { submitting.current = false; },
                    });
                }}>
                    <DialogHeader>
                        <DialogTitle>Jadwal ulang kunjungan {visit.visit_no}</DialogTitle>
                        <DialogDescription>Jadwal dan booking pengganti dikonfirmasi bersama. Jika bentrok atau gagal, reservasi lama tetap berlaku. Riwayat dan keanggotaan WO tetap tersimpan.</DialogDescription>
                    </DialogHeader>
                    <VisitFormErrors errors={form.errors} refreshUrl={refreshUrl} />
                    <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-2">
                        <legend className="sr-only">Jadwal pengganti</legend>
                        <FormField id={`${prefix}-start`} label="Mulai *" error={form.errors.starts_at}>
                            <input id={`${prefix}-start`} name="starts_at" type="datetime-local" required step={60} className="field-control"
                                value={form.data.starts_at.slice(0, 16)} aria-invalid={!!form.errors.starts_at}
                                aria-describedby={form.errors.starts_at ? `${prefix}-start-error` : undefined}
                                onChange={event => changeTime('starts_at', event.target.value)} />
                        </FormField>
                        <FormField id={`${prefix}-end`} label="Selesai *" error={form.errors.ends_at}>
                            <input id={`${prefix}-end`} name="ends_at" type="datetime-local" required step={60} className="field-control"
                                value={form.data.ends_at.slice(0, 16)} aria-invalid={!!form.errors.ends_at}
                                aria-describedby={form.errors.ends_at ? `${prefix}-end-error` : undefined}
                                onChange={event => changeTime('ends_at', event.target.value)} />
                        </FormField>
                        <FormField id={`${prefix}-zone`} label="Zona waktu *" error={form.errors.zone_name}>
                            <select id={`${prefix}-zone`} name="zone_name" className="field-control" value={form.data.zone_name}
                                aria-invalid={!!form.errors.zone_name} aria-describedby={form.errors.zone_name ? `${prefix}-zone-error` : undefined}
                                onChange={event => {
                                    const zone = event.target.value;
                                    changeTime('starts_at', form.data.starts_at.slice(0, 16), zone);
                                    changeTime('ends_at', form.data.ends_at.slice(0, 16), zone);
                                    form.setData('zone_name', zone);
                                }}>
                                {zones.map(zone => <option key={zone.value} value={zone.value}>{zone.label}</option>)}
                            </select>
                        </FormField>
                    </fieldset>
                    <p className="text-xs text-muted-foreground">Untuk lintas tengah malam, pilih tanggal selesai berikutnya. Ubah waktu atau peserta; perubahan alasan saja tidak cukup.</p>
                    <fieldset disabled={form.processing} className="flex min-w-0 flex-col gap-2" aria-invalid={!!participantErrors}
                        aria-describedby={`${prefix}-participants-hint${participantErrors ? ` ${prefix}-participants-error` : ''}`}>
                        <legend className="mb-2 text-sm font-medium">Peserta pengganti *</legend>
                        <p id={`${prefix}-participants-hint`} className="text-sm text-muted-foreground">Hanya anggota WO yang masih eligible dapat dipilih. Periksa kembali peserta jika akun atau penugasan berubah.</p>
                        {technicians.map(technician => <label key={technician.id} className="flex min-h-11 items-center gap-3 rounded-md border border-border px-3 py-2 text-sm">
                            <input type="checkbox" name="technician_ids[]" value={technician.id} className="size-4 shrink-0 accent-primary"
                                checked={form.data.technician_ids.includes(technician.id)} onChange={event => form.setData('technician_ids', event.target.checked
                                    ? [...form.data.technician_ids, technician.id] : form.data.technician_ids.filter(id => id !== technician.id))} />
                            <span className="min-w-0 break-words">{technician.name}</span>
                        </label>)}
                        {technicians.length === 0 && <p role="status" className="text-sm text-muted-foreground">Belum ada anggota WO yang eligible. Periksa penugasan terlebih dahulu.</p>}
                        {hasUnavailableSelection && <div role="alert" className="text-sm text-destructive">
                            <p>Sebagian peserta pilihan tidak lagi eligible. Hapus pilihan tersebut atau muat ulang data sebelum mencoba lagi.</p>
                            <Button type="button" variant="outline" onClick={() => form.setData('technician_ids', form.data.technician_ids.filter(id => technicians.some(technician => technician.id === id)))}>Hapus peserta yang tidak lagi eligible</Button>
                        </div>}
                        {participantErrors && <p id={`${prefix}-participants-error`} role="alert" className="text-sm text-destructive">{participantErrors}</p>}
                    </fieldset>
                    <FormField id={`${prefix}-reason`} label="Alasan jadwal ulang *" error={form.errors.reason}>
                        <textarea id={`${prefix}-reason`} name="reason" required maxLength={10000} disabled={form.processing}
                            className="field-control h-auto min-h-24 resize-y py-2" value={form.data.reason}
                            aria-invalid={!!form.errors.reason} aria-describedby={form.errors.reason ? `${prefix}-reason-error` : undefined}
                            onChange={event => form.setData('reason', event.target.value)} />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>Kembali</Button>
                        <Button type="submit" className="min-h-10 hover:bg-primary-hover" disabled={form.processing || form.data.reason.trim() === '' || form.data.technician_ids.length === 0}
                            aria-busy={form.processing}>{form.processing ? 'Menyimpan…' : 'Konfirmasi jadwal pengganti'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
