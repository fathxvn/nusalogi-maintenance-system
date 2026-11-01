import { useForm } from '@inertiajs/react';
import { useId, useState } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { roleLabels } from '@/lib/presentation';
import type { UserRecord, UserRole } from '@/types';

type Props = {
    user: UserRecord;
    action: string;
    decision: 'approve' | 'reject';
    roles: UserRole[];
};
export function UserApprovalAction({ user, action, decision, roles }: Props) {
    const [open, setOpen] = useState(false);
    const roleId = useId();
    const form = useForm<{ role: string; approval_status?: string }>({ role: '' });
    const approving = decision === 'approve';
    const label = approving ? 'Setujui' : 'Tolak';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger
                render={<Button type="button" variant="ghost" className="h-9 px-2 text-xs" />}
                aria-label={`${label} pendaftaran ${user.name}`}
            >
                {label}
            </DialogTrigger>
            <DialogContent className="rounded-lg">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(action, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                    className="flex flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>{label} pendaftaran?</DialogTitle>
                        <DialogDescription>
                            {approving
                                ? `Pilih role untuk ${user.name}. Akun akan diaktifkan setelah disetujui.`
                                : `Pendaftaran ${user.name} akan ditolak. Akun tetap nonaktif dan tersimpan untuk peninjauan.`}
                        </DialogDescription>
                    </DialogHeader>
                    {approving && (
                        <FormField id={roleId} label="Role awal" error={form.errors.role}>
                            <select
                                id={roleId}
                                className="field-control"
                                required
                                value={form.data.role}
                                disabled={form.processing}
                                aria-invalid={!!form.errors.role}
                                aria-describedby={form.errors.role ? `${roleId}-error` : undefined}
                                onChange={(event) => form.setData('role', event.target.value)}
                            >
                                <option value="">Pilih role</option>
                                {roles.map((role) => (
                                    <option key={role} value={role}>{roleLabels[role]}</option>
                                ))}
                            </select>
                        </FormField>
                    )}
                    {form.errors.approval_status && (
                        <p role="alert" className="text-sm text-destructive">
                            {form.errors.approval_status}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => setOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant={approving ? 'default' : 'destructive'}
                            disabled={form.processing || (approving && !form.data.role)}
                            aria-busy={form.processing}
                        >
                            {form.processing ? 'Menyimpan…' : `${label} pendaftaran`}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
