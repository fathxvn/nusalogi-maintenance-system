import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { VisitFormErrors } from '@/components/visit-form-errors';
import type { BookingRecord } from '@/types';

type Props = { booking: BookingRecord; visitVersion: number; refreshUrl: string };

export function VisitBookingCancelAction({ booking, visitVersion, refreshUrl }: Props) {
    const [open, setOpen] = useState(false);
    const submitting = useRef(false);
    const form = useForm({
        command_key: booking.command_key ?? '', expected_visit_version: visitVersion,
        expected_booking_status: booking.status, reason: '',
    });

    function changeOpen(next: boolean) {
        if (form.processing || submitting.current) return;
        setOpen(next);
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger render={<Button type="button" variant="outline" className="min-h-10 text-destructive" disabled={form.processing} />}
                aria-label={`Batalkan booking ${booking.technician_name}`}>
                Batalkan booking
            </DialogTrigger>
            <DialogContent showCloseButton={!form.processing}>
                <form className="flex flex-col gap-4" aria-label={`Pembatalan booking ${booking.technician_name}`} onSubmit={event => {
                    event.preventDefault();
                    if (!booking.cancel_url || !booking.command_key || form.processing || submitting.current || form.data.reason.trim() === '') return;
                    submitting.current = true;
                    form.post(booking.cancel_url, {
                        preserveScroll: true, errorBag: `cancel-booking-${booking.id}`,
                        onSuccess: () => setOpen(false),
                        onFinish: () => { submitting.current = false; },
                    });
                }}>
                    <DialogHeader>
                        <DialogTitle>Batalkan booking?</DialogTitle>
                        <DialogDescription>
                            Booking {booking.technician_name} akan dibatalkan. Keanggotaan WO tetap.
                            Jika ini peserta terakhir yang belum bekerja, kunjungan dapat menjadi dibatalkan. Status WO tidak otomatis berubah.
                        </DialogDescription>
                    </DialogHeader>
                    <VisitFormErrors errors={form.errors} refreshUrl={refreshUrl} />
                    <FormField id={`cancel-booking-${booking.id}-reason`} label="Alasan pembatalan *" error={form.errors.reason}>
                        <textarea id={`cancel-booking-${booking.id}-reason`} name="reason" required maxLength={10000}
                            className="field-control h-auto min-h-28 resize-y py-2" value={form.data.reason} disabled={form.processing}
                            aria-invalid={!!form.errors.reason} aria-describedby={form.errors.reason ? `cancel-booking-${booking.id}-reason-error` : undefined}
                            onChange={event => form.setData('reason', event.target.value)} />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>Kembali</Button>
                        <Button type="submit" variant="destructive" disabled={form.processing || form.data.reason.trim() === '' || !booking.cancel_url || !booking.command_key} aria-busy={form.processing}>
                            {form.processing ? 'Membatalkan…' : 'Batalkan booking'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
