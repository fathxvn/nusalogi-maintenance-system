import type { ApprovalStatus, UserRole } from '@/types';

export const approvalLabels: Record<ApprovalStatus, string> = {
    pending: 'Menunggu persetujuan',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

export const roleLabels: Record<UserRole, string> = {
    admin: 'Administrator',
    koordinator: 'Koordinator',
    teknisi: 'Teknisi',
};
export const workOrderLabels: Record<string, string> = {
    pending: 'Menunggu',
    assigned: 'Ditugaskan',
    in_progress: 'Dikerjakan',
    on_hold: 'Ditahan',
    pending_review: 'Menunggu review',
    rework: 'Perlu perbaikan',
    awaiting_acceptance: 'Menunggu penerimaan',
    closed: 'Ditutup',
    cancelled: 'Dibatalkan',
};
export const priorityLabels: Record<string, string> = {
    high: 'Tinggi',
    medium: 'Sedang',
    low: 'Rendah',
};
export function dateLabel(value: string | null): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value.includes('T') ? value : `${value}T12:00:00`));
}
export function statusClass(status: string): string {
    if (['in_progress', 'closed'].includes(status))
        return 'bg-teal-50 text-teal-800 dark:bg-teal-950 dark:text-teal-200';
    if (['assigned', 'pending_review', 'awaiting_acceptance'].includes(status))
        return 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200';
    if (['pending', 'on_hold', 'rework'].includes(status))
        return 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
    return 'bg-muted text-muted-foreground';
}
