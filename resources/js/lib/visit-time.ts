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
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) {
        throw new RangeError('Waktu atau zona waktu tidak valid.');
    }
    if (option) return `${local}${option.offset}`;

    // Existing API-created visits may use another IANA zone. Reject ambiguous
    // or nonexistent wall times rather than silently choosing a DST instant.
    const timestamp = Date.parse(`${local}Z`);
    if (!Number.isFinite(timestamp)) throw new RangeError('Waktu tidak valid.');
    const offsets = new Set([-36, 0, 36].map(hours => visitOffsetAt(new Date(timestamp + hours * 3600000), zone)));
    const matches = [...offsets].map(offset => `${local}${offset}`).filter(value => visitLocalTime(value, zone) === local);
    if (matches.length !== 1) throw new RangeError('Waktu tidak tersedia atau ambigu pada zona ini. Pilih waktu atau zona lain.');
    return matches[0];
}

function visitOffsetAt(instant: Date, zone: string): string {
    const offset = new Intl.DateTimeFormat('en-GB', { timeZone: zone, timeZoneName: 'longOffset' })
        .formatToParts(instant).find(part => part.type === 'timeZoneName')?.value;
    if (offset === 'GMT') return '+00:00';
    if (!offset || !/^GMT[+-]\d{2}:\d{2}$/.test(offset)) throw new RangeError('Zona waktu tidak valid.');
    return offset.slice(3);
}

export function visitLocalTime(instant: string, zone: string): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(instant));
    const part = (type: Intl.DateTimeFormatPartTypes) => parts.find(candidate => candidate.type === type)?.value;
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

export function visitInputValue(instant: string, zone: string): string {
    return `${visitLocalTime(instant, zone)}${visitOffsetAt(new Date(instant), zone)}`;
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
    VISIT_RESCHEDULE_NOT_ALLOWED: 'Kunjungan ini tidak dapat dijadwal ulang. Muat ulang data; kunjungan yang sudah mulai harus tetap dipertahankan.',
    VISIT_RESCHEDULE_NO_CHANGES: 'Ubah waktu atau peserta sebelum menjadwal ulang. Alasan saja tidak mengubah reservasi.',
};

export function visitErrorLabel(message: string): string {
    return conflictMessages[message] ?? message;
}
