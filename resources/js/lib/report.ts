import type { VisitWorkOrder } from '@/types';

export type ReportAction = { url: string; command_key: string; upload_key?: string };
export type ChecklistItem = { code: string; label: string; outcome: string; notes?: string | null };
export type AssetResult = { asset_id: number; result: string; notes?: string | null };
export type MaterialMovement = { id: number; item_description: string; quantity: string; unit_code: string; movement_kind: string; adjusts_usage_id: number | null; supply_source: string; disposition: string; reason: string | null };
export type ReportRevision = { id: number; revision_no: number; status: string; url: string; author?: string };
export type ReportWorkOrder = VisitWorkOrder & { lock_version: number };
export type ReportRecord = {
    id: number; revision_no: number | null; previous_revision_id: number | null; review_status: string;
    diagnosis: string | null; action_taken: string | null; result: string | null; recommendation: string | null;
    checklist_snapshot: ChecklistItem[] | null; asset_results: AssetResult[] | null; usage_snapshot: MaterialMovement[] | null;
    revision_category?: string | null;
    review_notes: string | null; lock_version: number; author: string; reviewer: string | null;
    submitted_at: string | null; reviewed_at: string | null; is_head: boolean; legacy: boolean;
    scope: { id: number; scope_no: number; description: string; acceptance_criteria: string } | null;
    photos: { id: number; phase: string; caption: string | null; name: string; download_url: string | null }[];
};
export type ReportShowProps = {
    workOrder: ReportWorkOrder; report: ReportRecord; revisions: ReportRevision[];
    materials: MaterialMovement[]; assets: { id: number; asset_code: string }[];
    actions: Record<'update' | 'submit' | 'review' | 'revise' | 'evidence' | 'material' | 'correction', ReportAction | null>;
    correctionAuthors?: { id: number; name: string }[];
    links: { index: string; refresh: string };
};
export type ReportIndexProps = {
    workOrder: ReportWorkOrder;
    scopes: { id: number; scope_no: number; description: string; acceptance_criteria: string; is_required: boolean; disposition: string; create: ReportAction | null; revisions: ReportRevision[] }[];
    legacyReports: { id: number; status: string; url: string }[]; unavailableReason: string | null;
    links: { index: string; refresh: string; visits: string; acceptance?: string; lifecycle?: string };
};

export function reportStatus(status: string): string {
    return ({ draft: 'Draft', submitted: 'Menunggu review', approved: 'Disetujui QA', needs_revision: 'Perlu revisi' } as Record<string, string>)[status] ?? status;
}

export function reportError(message: string): string {
    const messages: Record<string, string> = {
        ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED: 'Seluruh scope wajib aktif harus memiliki revisi approved dan acceptance customer yang valid.',
        REQUIRED_SCOPES_MISSING: 'WO memerlukan sedikitnya satu scope wajib aktif untuk ditutup.',
        EXCEPTION_CLOSURE_DISABLED: 'Penutupan melalui pengecualian tidak tersedia pada M1.',
        ACTIVE_BOOKINGS_BLOCK_CLOSURE: 'Selesaikan atau batalkan booking aktif yang masih tersisa sebelum menutup WO.',
        ACTIVE_BOOKING_BLOCKS_HOLD: 'Selesaikan sesi kerja yang sedang berjalan sebelum menahan WO.',
        CANCELLATION_AUTHORITY_REQUIRED: 'Pembatalan pekerjaan yang telah dimulai memerlukan keputusan kewenangan khusus.',
        HOLD_PROVENANCE_REQUIRED: 'Asal status hold belum terverifikasi. Koordinator perlu meninjau histori WO.',
        CONTEXT_REFERENCED_BY_BAST: 'Konteks ini sudah dirujuk oleh BAST. Paket harus ditangani melalui versi dan histori yang sah.',
        CLOSURE_PROOF_CHANGED: 'Bukti acceptance berubah saat diperiksa. Muat ulang dan coba lagi.',
        CLOSURE_PROVENANCE_REQUIRED: 'Bukti dan histori closure belum lengkap atau belum terverifikasi.',
        TICKET_VERSION_CONFLICT: 'Ticket berubah. Muat ulang data sebelum mengirim keputusan baru.',
        TICKET_STATE_CONFLICT: 'Tindakan tidak tersedia pada status ticket sekarang.',
        TICKET_WORK_ORDERS_STILL_OPEN: 'Masih ada WO pada ticket ini yang belum selesai atau memiliki disposition sah.',
        TICKET_OPERATIONAL_SCOPES_STILL_OPEN: 'Masih ada scope operasional yang belum dituntaskan dengan bukti yang sah.',
        TICKET_FULFILLMENT_PROVENANCE_REQUIRED: 'Histori penyelesaian WO belum terverifikasi; ticket tetap terbuka untuk review.',
        REMOTE_RESOLUTION_NOT_ELIGIBLE: 'Resolusi remote memerlukan hasil terkonfirmasi, coverage jelas, dan tidak boleh melewati WO yang sudah ada.',
        TICKET_CANCELLATION_REQUIRES_CHILD_DISPOSITION: 'Tinjau dan tangani seluruh WO terkait sebelum membatalkan ticket.',
        CANONICAL_TICKET_REQUIRED: 'Pilih ticket canonical yang berbeda dan bukan duplikat.',

        REWORK_CATEGORY_MISMATCH: 'Jenis koreksi harus mengikuti kategori keputusan QA sebelumnya.',
        BAST_PARENT_SET_CHANGED: 'Isi paket atau peserta berubah. Muat ulang dan susun ulang paket.',
        BAST_APPROVED_HEAD_REQUIRED: 'Setiap scope harus memakai revisi terbaru yang approved dan tidak boleh dimasukkan dua kali.',
        BAST_CONTEXT_MISMATCH: 'Customer, konteks acceptance, atau status WO dalam paket tidak cocok.',
        BAST_AUTHORITY_SCOPE_MISMATCH: 'Bukti kewenangan PIC harus mencakup seluruh site dalam paket.',
        BAST_SCOPE_ALREADY_CLAIMED: 'Scope sudah berada pada BAST pending atau telah diterima customer.',
        BAST_VERSION_CONFLICT: 'Versi dokumen berubah atau telah digantikan. Muat ulang sebelum melanjutkan.',
        BAST_STATE_CONFLICT: 'Tindakan tidak tersedia pada status versi BAST ini.',
        BAST_ACCEPTED_IMMUTABLE: 'Keputusan acceptance final dipertahankan. Gunakan pekerjaan tindak lanjut untuk kebutuhan baru.',
        ACCEPTED_SCOPE_IMMUTABLE: 'Scope telah diterima customer. Koreksi memerlukan pekerjaan tindak lanjut.',
        ACTIVE_BOOKING_BLOCKS_REWORK: 'Selesaikan sesi yang sedang berjalan sebelum membuka rework teknis.',

        REPORT_VERSION_CONFLICT: 'Laporan berubah. Simpan salinan isian Anda lalu muat ulang data.',
        WORK_ORDER_VERSION_CONFLICT: 'Status WO berubah. Muat ulang data sebelum melanjutkan.',
        REPORT_REVISION_CONFLICT: 'Sudah ada revisi yang lebih baru. Buka revisi terbaru.',
        REPORT_STATE_CONFLICT: 'Revisi ini tidak dapat diubah pada status sekarang.',
        WORK_ORDER_STATE_CONFLICT: 'Tindakan tidak tersedia pada status WO sekarang.',
        REPORT_ALREADY_EXISTS: 'Draft sudah dibuat. Muat ulang untuk membukanya.',
        COMMAND_KEY_REUSED: 'Permintaan sebelumnya memiliki isi berbeda. Muat ulang sebelum mencoba lagi.',
        MATERIAL_RETURN_EXCEEDS_USAGE: 'Jumlah pengembalian atau reversal melebihi sisa pemakaian asal.',
        MATERIAL_PARENT_MISMATCH: 'Material, satuan, sumber, dan disposition harus sama dengan pemakaian asal.',
        MATERIAL_PARENT_MUST_BE_CONSUME: 'Koreksi harus merujuk catatan pemakaian asal.',
        EVIDENCE_HASH_MISMATCH: 'File berubah saat diunggah. Pilih ulang file.',
        EVIDENCE_INTEGRITY_FAILED: 'File tidak tersedia atau isinya tidak cocok. Hubungi Koordinator untuk pemeriksaan bukti.',
        EVIDENCE_NOT_READY: 'Evidence belum siap digunakan. Coba lagi atau pilih file baru.',
        EVIDENCE_QUARANTINED: 'File dikarantina. Gunakan unggahan baru.',
        ACTIVE_BOOKING_BLOCKS_SUBMISSION: 'Selesaikan seluruh sesi kerja yang sedang berjalan sebelum mengajukan laporan.',
        WORK_ORDER_PARTICIPANTS_CHANGED: 'Peserta kunjungan berubah. Muat ulang data.',
        REWORK_MUST_START_BEFORE_SUBMISSION: 'Mulai sesi rework sebelum mengajukan laporan perbaikan.',
        WORK_ORDER_M1_BASELINE_REQUIRED: 'Origin, konteks acceptance, dan scope wajib WO belum lengkap.',
        REPORT_M1_BASELINE_REQUIRED: 'Laporan historis ini belum dapat memakai workflow revisi M1.',
        SCOPE_STATE_CONFLICT: 'Scope ini tidak aktif.',
    };
    return messages[message] ?? (/^[A-Z][A-Z0-9_]+$/.test(message) ? 'Tindakan belum dapat diproses. Muat ulang data dan periksa status pekerjaan.' : message);
}

export function reportTime(value: string | null): string {
    return value ? `${new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))} WIB` : '—';
}
