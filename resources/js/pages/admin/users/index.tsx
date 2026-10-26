import { Link, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import { UserStatusAction } from '@/components/user-status-action';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { dateLabel, roleLabels } from '@/lib/presentation';
import { cn } from '@/lib/utils';
import type { Paginated, UserRecord, UserRole } from '@/types';

type Row = UserRecord & { links: { edit: string; status: string }; is_self: boolean };
type Props = {
    users: Paginated<Row>;
    roles: UserRole[];
    filters: { search: string; role: string; status: string };
    links: { index: string; create: string };
};
export default function UserIndex({ users, roles, filters, links }: Props) {
    const form = useForm(filters);
    function submit(event: FormEvent) {
        event.preventDefault();
        form.get(links.index, { preserveState: true, preserveScroll: true, replace: true });
    }
    return (
        <>
            <div className="flex justify-end">
                <Link
                    href={links.create}
                    className={cn(buttonVariants(), 'h-10 rounded-md px-4 hover:bg-primary-hover')}
                >
                    <Plus aria-hidden="true" />
                    Tambah Pengguna
                </Link>
            </div>
            <form
                onSubmit={submit}
                className="flex flex-wrap items-end gap-3"
                aria-label="Filter pengguna"
            >
                <div className="flex min-w-52 flex-1 flex-col gap-2">
                    <label htmlFor="user-search" className="text-xs font-medium">
                        Cari nama atau email
                    </label>
                    <input
                        id="user-search"
                        type="search"
                        maxLength={100}
                        className="field-control"
                        value={form.data.search}
                        onChange={(event) => form.setData('search', event.target.value)}
                        placeholder="Nama atau email"
                    />
                    {form.errors.search && (
                        <p role="alert" className="text-xs text-destructive">
                            {form.errors.search}
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2">
                    <label htmlFor="role-filter" className="text-xs font-medium">
                        Role
                    </label>
                    <select
                        id="role-filter"
                        className="field-control"
                        value={form.data.role}
                        onChange={(event) => form.setData('role', event.target.value)}
                    >
                        <option value="">Semua role</option>
                        {roles.map((role) => (
                            <option key={role} value={role}>
                                {roleLabels[role]}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex flex-col gap-2">
                    <label htmlFor="status-filter" className="text-xs font-medium">
                        Status
                    </label>
                    <select
                        id="status-filter"
                        className="field-control"
                        value={form.data.status}
                        onChange={(event) => form.setData('status', event.target.value)}
                    >
                        <option value="">Semua status</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    className="h-10 rounded-md"
                    disabled={form.processing}
                >
                    <Search aria-hidden="true" />
                    Terapkan
                </Button>
                <Link
                    href={links.index}
                    className="inline-flex min-h-10 items-center px-2 text-sm text-muted-foreground underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-ring"
                >
                    Reset filter
                </Link>
            </form>
            <div className="table-shell">
                <table className="soft-table">
                    <thead>
                        <tr>
                            {['Nama', 'Email', 'Role', 'Status', 'Dibuat', 'Aksi'].map((label) => (
                                <th key={label}>{label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {users.data.length ? (
                            users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="font-medium">
                                        {user.name}
                                        {user.is_self && (
                                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                Anda
                                            </span>
                                        )}
                                    </td>
                                    <td>{user.email}</td>
                                    <td>
                                        {user.role
                                            ? (roleLabels[user.role] ?? user.role)
                                            : 'Belum ditetapkan'}
                                    </td>
                                    <td>
                                        <span
                                            className={cn(
                                                'status-badge',
                                                user.is_active
                                                    ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                                    : 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
                                            )}
                                        >
                                            {user.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap">
                                        {dateLabel(user.created_at ?? null)}
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-1 whitespace-nowrap">
                                            {user.is_self ? (
                                                <span className="px-2 text-xs text-muted-foreground">
                                                    Edit role dibatasi
                                                </span>
                                            ) : (
                                                <Link
                                                    href={user.links.edit}
                                                    className={cn(
                                                        buttonVariants({ variant: 'ghost' }),
                                                        'h-9 px-2 text-xs',
                                                    )}
                                                >
                                                    Edit Role
                                                </Link>
                                            )}
                                            <UserStatusAction
                                                user={user}
                                                action={user.links.status}
                                                disabled={user.is_self}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={6} className="empty-state">
                                    Tidak ada pengguna yang sesuai dengan filter.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <nav
                aria-label="Pagination pengguna"
                className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
            >
                <p>
                    {users.from ?? 0}–{users.to ?? 0} dari {users.total} pengguna · Halaman{' '}
                    {users.current_page} / {users.last_page}
                </p>
                <div className="flex gap-2">
                    {users.prev_page_url ? (
                        <Link
                            preserveScroll
                            href={users.prev_page_url}
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        >
                            Sebelumnya
                        </Link>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Sebelumnya
                        </Button>
                    )}
                    {users.next_page_url ? (
                        <Link
                            preserveScroll
                            href={users.next_page_url}
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        >
                            Berikutnya
                        </Link>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Berikutnya
                        </Button>
                    )}
                </div>
            </nav>
        </>
    );
}
UserIndex.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Manajemen Pengguna" description="Kelola akun internal Nusalogi.">
        {page}
    </AuthenticatedLayout>
);
