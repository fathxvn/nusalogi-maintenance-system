import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter,
    DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { AssetRecord } from '@/types';

type Props = {
    asset: Pick<AssetRecord, 'asset_code' | 'brand' | 'model' | 'serial_number'>;
    action: string;
    className?: string;
};

export function AssetDeleteAction({ asset, action, className }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ asset?: string }>({});
    const submitting = useRef(false);
    const deviceName = [asset.brand, asset.model].filter(Boolean).join(' ');

    function changeOpen(next: boolean) {
        if (form.processing || submitting.current) {
            return;
        }

        setOpen(next);
        form.clearErrors();
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger
                render={<Button type="button" variant="ghost" disabled={form.processing} className={cn('h-9 px-2 text-xs text-destructive', className)} />}
                aria-label={`Hapus asset ${asset.asset_code}`}
            >
                Hapus
            </DialogTrigger>
            <DialogContent className="rounded-lg" showCloseButton={!form.processing}>
                <form className="flex flex-col gap-4" onSubmit={(event) => {
                    event.preventDefault();

                    if (form.processing || submitting.current) {
                        return;
                    }

                    submitting.current = true;
                    form.delete(action, {
                        preserveScroll: true,
                        onSuccess: () => setOpen(false),
                        onFinish: () => { submitting.current = false; },
                    });
                }}>
                    <DialogHeader>
                        <DialogTitle>Hapus Asset?</DialogTitle>
                        <DialogDescription className="break-words">
                            Asset {asset.asset_code}{deviceName ? ` (${deviceName})` : ''}{asset.serial_number ? `, nomor seri ${asset.serial_number}` : ''} akan dihapus.
                            Asset yang terkait Work Order, termasuk pekerjaan selesai, tidak dapat dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    {form.errors.asset && <p role="alert" className="text-sm text-destructive">{form.errors.asset}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>
                            Batal
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing} aria-busy={form.processing}>
                            {form.processing ? 'Menghapus…' : 'Hapus Asset'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
