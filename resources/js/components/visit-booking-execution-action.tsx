import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { VisitFormErrors } from '@/components/visit-form-errors';
import type { BookingRecord } from '@/types';

type Props = { booking: BookingRecord; visitVersion: number; refreshUrl: string };

export function VisitBookingExecutionAction({ booking, visitVersion, refreshUrl }: Props) {
    const [open, setOpen] = useState(false);
    const submitting = useRef(false);
    const form = useForm({
        command_key: booking.execution_command_key ?? '',
        expected_visit_version: visitVersion,
        expected_booking_status: booking.status,
    });
    const label = booking.execution_action === 'start' ? 'Mulai bekerja' : 'Selesaikan sesi';
    const permitted = !!booking.execution_action && !!booking.execution_url && !!booking.execution_command_key;

    function changeOpen(next: boolean) {
        if (form.processing || submitting.current) return;
        setOpen(next);
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger render={<Button type="button" variant="outline" className="min-h-10" disabled={form.processing || !permitted} />}
                aria-label={`${label} · ${booking.technician_name}`}>
                {label}
            </DialogTrigger>
            <DialogContent showCloseButton={!form.processing}>
                <form className="flex flex-col gap-4" aria-label={`${label} · ${booking.technician_name}`} onSubmit={event => {
                    event.preventDefault();
                    if (!permitted || !booking.execution_url || form.processing || submitting.current) return;
                    submitting.current = true;
                    form.post(booking.execution_url, {
                        preserveScroll: true, errorBag: `execute-booking-${booking.id}`,
                        onSuccess: () => setOpen(false),
                        onFinish: () => { submitting.current = false; },
                    });
                }}>
                    <DialogHeader>
                        <DialogTitle>{label}?</DialogTitle>
                        <DialogDescription>
                            {booking.execution_action === 'start'
                                ? 'Mulai sesi kerja untuk booking Anda. Waktu mulai aktual dicatat oleh server.'
                                : 'Selesaikan sesi kerja untuk booking Anda. Waktu selesai aktual dicatat oleh server. WO masih memerlukan laporan, review, dan acceptance sebelum closure.'}
                        </DialogDescription>
                    </DialogHeader>
                    <VisitFormErrors errors={form.errors} refreshUrl={refreshUrl} />
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>Kembali</Button>
                        <Button type="submit" disabled={form.processing || !permitted} aria-busy={form.processing}>
                            {form.processing ? 'Menyimpan…' : label}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
