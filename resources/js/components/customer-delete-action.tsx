import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter,
    DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { CustomerRecord } from '@/types';

type Props = { customer: Pick<CustomerRecord, 'name'>; action: string; className?: string };

export function CustomerDeleteAction({ customer, action, className }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ customer?: string }>({});

    return (
        <Dialog open={open} onOpenChange={(next) => {
            if (!form.processing) {
                setOpen(next);
                form.clearErrors();
            }
        }}>
            <DialogTrigger
                render={<Button type="button" variant="ghost" className={cn('h-9 px-2 text-xs text-destructive', className)} />}
                aria-label={`Hapus customer ${customer.name}`}
            >
                Hapus
            </DialogTrigger>
            <DialogContent className="rounded-lg">
                <form className="flex flex-col gap-4" onSubmit={(event) => {
                    event.preventDefault();
                    form.delete(action, { preserveScroll: true, onSuccess: () => setOpen(false) });
                }}>
                    <DialogHeader>
                        <DialogTitle>Hapus Customer?</DialogTitle>
                        <DialogDescription>
                            Customer {customer.name} akan dihapus. Customer yang masih memiliki site atau invoice terkait tidak dapat dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    {form.errors.customer && <p role="alert" className="text-sm text-destructive">{form.errors.customer}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>
                            Batal
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing} aria-busy={form.processing}>
                            {form.processing ? 'Menghapus…' : 'Hapus Customer'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
