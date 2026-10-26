import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { roleLabels } from '@/lib/presentation';
import type { SharedProps, UserRecord, UserRole } from '@/types';

type Props = { user: UserRecord; roles: UserRole[]; links: { index: string; update: string } };
export default function EditUser({ user, roles, links }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const isSelf = auth.user?.id === user.id;
    const form = useForm({ role: user.role ?? '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(links.update);
    }
    return (
        <form onSubmit={submit} className="panel flex w-full max-w-2xl flex-col gap-5">
            <div>
                <h2 className="section-title">{user.name}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{user.email}</p>
            </div>
            <FormField id="role" label="Role" error={form.errors.role}>
                <select
                    id="role"
                    className="field-control"
                    value={form.data.role}
                    required
                    disabled={isSelf}
                    aria-invalid={!!form.errors.role}
                    aria-describedby={form.errors.role ? 'role-error' : undefined}
                    onChange={(event) => form.setData('role', event.target.value)}
                >
                    <option value="">Pilih role</option>
                    {roles.map((role) => (
                        <option key={role} value={role}>
                            {roleLabels[role]}
                        </option>
                    ))}
                </select>
            </FormField>
            {isSelf && (
                <p className="text-sm text-muted-foreground">
                    Role akun sendiri tidak dapat diubah.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-4">
                <Button
                    type="submit"
                    className="h-10 px-4 hover:bg-primary-hover"
                    disabled={isSelf || form.processing}
                    aria-busy={form.processing}
                >
                    {form.processing ? 'Menyimpan…' : 'Simpan role'}
                </Button>
                <Link href={links.index} className="text-sm underline underline-offset-4">
                    Kembali
                </Link>
            </div>
        </form>
    );
}
EditUser.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Edit Role"
        description="Sesuaikan akses pengguna melalui role yang tersedia."
    >
        {page}
    </AuthenticatedLayout>
);
