export const visitZones = [
    { value: 'Asia/Jakarta', label: 'WIB — Asia/Jakarta', offset: '+07:00' },
    { value: 'Asia/Makassar', label: 'WITA — Asia/Makassar', offset: '+08:00' },
    { value: 'Asia/Jayapura', label: 'WIT — Asia/Jayapura', offset: '+09:00' },
] as const;

// Keep the user's wall-clock time. Never apply the browser's local timezone.
// The service independently validates the IANA zone and the supplied UTC offset.
export function withVisitOffset(local: string, zone: string): string {
    if (local === '') return '';
    const option = visitZones.find(candidate => candidate.value === zone);
    if (!option || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) {
        throw new RangeError('Waktu atau zona waktu tidak valid.');
    }
    return `${local}${option.offset}`;
}

export function visitDateLabel(instant: string, zone: string): string {
    try {
        return new Intl.DateTimeFormat('id-ID', {
            timeZone: zone, day: 'numeric', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).format(new Date(instant));
    } catch {
        return 'Waktu tidak valid';
    }
}

export const visitStatusLabels: Record<string, string> = {
    draft: 'Draft', confirmed: 'Dikonfirmasi', in_progress: 'Dikerjakan',
    completed: 'Selesai', cancelled: 'Dibatalkan', superseded: 'Diganti jadwal baru',
};

const conflictMessages: Record<string, string> = {
    BOOKING_TIME_CONFLICT: 'Jadwal salah satu peserta bentrok. Tidak ada peserta yang dibooking; ubah pilihan atau muat ulang data.',
    VISIT_VERSION_CONFLICT: 'Data kunjungan sudah berubah. Muat ulang sebelum mencoba lagi.',
    COMMAND_KEY_REUSED: 'Permintaan ini sudah dipakai dengan data berbeda. Muat ulang untuk membuat permintaan baru.',
    WORK_ORDER_M1_BASELINE_REQUIRED: 'WO belum memenuhi baseline M1. Hubungi Koordinator untuk memeriksa origin dan scope.',
    WORK_ORDER_STATE_CONFLICT: 'Status Work Order sudah berubah dan tidak mengizinkan tindakan ini.',
    VISIT_STATE_CONFLICT: 'Status kunjungan sudah berubah. Muat ulang data.',
    BOOKING_STATE_CONFLICT: 'Status booking sudah berubah. Muat ulang data.',
    BOOKING_CANCELLATION_NOT_ALLOWED: 'Booking ini tidak dapat dibatalkan. Pekerjaan yang sudah dimulai tidak dapat diinterupsi melalui tindakan ini.',
    VISIT_BOOKING_STATE_ANOMALY: 'Data booking perlu diperiksa Koordinator. Tindakan belum disimpan.',
    BOOKING_RESOURCE_ANOMALY: 'Data teknisi perlu diperiksa Koordinator. Tindakan belum disimpan.',
    BOOKING_MEMBERSHIP_ANOMALY: 'Keanggotaan teknisi sudah berubah. Tindakan belum disimpan.',
    VISIT_RESOURCES_CHANGED: 'Peserta berubah saat diproses. Muat ulang dan coba lagi.',
    VISIT_INTERVAL_INVALID: 'Interval kunjungan tidak valid. Hubungi Koordinator.',
};

export function visitErrorLabel(message: string): string {
    return conflictMessages[message] ?? message;
}
