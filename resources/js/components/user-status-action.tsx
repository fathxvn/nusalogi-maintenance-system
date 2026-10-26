import { useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import type { UserRecord } from '@/types';

type Props = { user: UserRecord; action: string; disabled: boolean };
export function UserStatusAction({ user, action, disabled }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ is_active: !user.is_active });
    const label = user.is_active ? 'Nonaktifkan' : 'Aktifkan';
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);
                    form.clearErrors();
                    form.setData('is_active', !user.is_active);
                }
            }}
        >
            <DialogTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={disabled}
                        className="h-9 rounded-md px-2 text-xs"
                    />
                }
                aria-label={`${label} akun ${user.name}`}
            >
                {label}
            </DialogTrigger>
            <DialogContent className="rounded-lg">
                <DialogHeader>
                    <DialogTitle>{label} akun?</DialogTitle>
                    <DialogDescription>
                        {user.is_active
                            ? `Akun ${user.name} tidak dapat masuk setelah dinonaktifkan. Sesi aktifnya akan dihentikan pada permintaan berikutnya.`
                            : `Akun ${user.name} akan dapat masuk kembali.`}
                    </DialogDescription>
                </DialogHeader>
                {form.errors.is_active && (
                    <p role="alert" className="text-sm text-destructive">
                        {form.errors.is_active}
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
                        type="button"
                        disabled={form.processing}
                        aria-busy={form.processing}
                        className="hover:bg-primary-hover"
                        onClick={() =>
                            form.patch(action, {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            })
                        }
                    >
                        {form.processing ? 'Menyimpan…' : `${label} akun`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
