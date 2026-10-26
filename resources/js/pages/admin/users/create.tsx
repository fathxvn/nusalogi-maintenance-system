import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { roleLabels } from '@/lib/presentation';
import type { UserRole } from '@/types';

type Props = { roles: UserRole[]; links: { index: string; store: string } };
export default function CreateUser({ roles, links }: Props) {
    const form = useForm({
        name: '',
        email: '',
        role: '',
        password: '',
        password_confirmation: '',
    });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(links.store, { onFinish: () => form.reset('password', 'password_confirmation') });
    }
    return (
        <form
            onSubmit={submit}
            className="panel flex w-full max-w-2xl flex-col gap-5"
            aria-label="Tambah pengguna"
        >
            <FormField id="name" label="Nama" error={form.errors.name}>
                <input
                    id="name"
                    className="field-control"
                    required
                    maxLength={255}
                    autoComplete="name"
                    autoFocus
                    value={form.data.name}
                    aria-invalid={!!form.errors.name}
                    aria-describedby={form.errors.name ? 'name-error' : undefined}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </FormField>
            <FormField id="email" label="Email" error={form.errors.email}>
                <input
                    id="email"
                    type="email"
                    className="field-control"
                    required
                    maxLength={255}
                    autoComplete="email"
                    value={form.data.email}
                    aria-invalid={!!form.errors.email}
                    aria-describedby={form.errors.email ? 'email-error' : undefined}
                    onChange={(event) => form.setData('email', event.target.value)}
                />
            </FormField>
            <FormField id="role" label="Role" error={form.errors.role}>
                <select
                    id="role"
                    className="field-control"
                    required
                    value={form.data.role}
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
            <FormField id="password" label="Password" error={form.errors.password}>
                <input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    className="field-control"
                    required
                    value={form.data.password}
                    aria-invalid={!!form.errors.password}
                    aria-describedby={form.errors.password ? 'password-error' : undefined}
                    onChange={(event) => form.setData('password', event.target.value)}
                />
            </FormField>
            <FormField
                id="password_confirmation"
                label="Konfirmasi password"
                error={form.errors.password_confirmation}
            >
                <input
                    id="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    className="field-control"
                    required
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                />
            </FormField>
            <div className="flex flex-wrap items-center gap-4 pt-2">
                <Button
                    type="submit"
                    className="h-10 px-4 hover:bg-primary-hover"
                    disabled={form.processing}
                    aria-busy={form.processing}
                >
                    {form.processing ? 'Menyimpan…' : 'Simpan pengguna'}
                </Button>
                <Link href={links.index} className="text-sm underline underline-offset-4">
                    Batal
                </Link>
            </div>
        </form>
    );
}
CreateUser.layout = (page: ReactNode) => (
    <AuthenticatedLayout
        title="Tambah Pengguna"
        description="Buat akun internal dengan role yang sesuai."
    >
        {page}
    </AuthenticatedLayout>
);
