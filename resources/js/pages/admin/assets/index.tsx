import { Link, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { cn } from '@/lib/utils';
import type { AssetRecord, Paginated } from '@/types';

type AssetListItem = AssetRecord & {
    links: {
        show: string;
    };
};

type Props = {
    assets: Paginated<AssetListItem>;
    filters: {
        search: string;
    };
    links: {
        index: string;
        create: string;
    };
};

export default function AssetIndex({ assets, filters, links }: Props) {
    const form = useForm({
        search: filters.search,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        form.get(links.index, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <>
            <div className="flex justify-end">
                <Link
                    href={links.create}
                    className={cn(
                        buttonVariants(),
                        'h-10 px-4 hover:bg-primary-hover',
                    )}
                >
                    <Plus aria-hidden="true" />
                    Tambah Asset
                </Link>
            </div>

            <form
                onSubmit={submit}
                className="flex flex-wrap items-end gap-3"
                aria-label="Pencarian asset"
            >
                <div className="flex min-w-52 flex-1 flex-col gap-2">
                    <label
                        htmlFor="asset-search"
                        className="text-xs font-medium"
                    >
                        Cari kode, kategori, merek, model, atau nomor seri
                    </label>

                    <input
                        id="asset-search"
                        name="search"
                        type="search"
                        className="field-control"
                        placeholder="Cari asset..."
                        maxLength={100}
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                        aria-invalid={!!form.errors.search}
                        aria-describedby={
                            form.errors.search
                                ? 'asset-search-error'
                                : undefined
                        }
                    />

                    {form.errors.search && (
                        <p
                            id="asset-search-error"
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {form.errors.search}
                        </p>
                    )}
                </div>

                <Button
                    type="submit"
                    variant="outline"
                    className="h-10 rounded-md"
                    disabled={form.processing}
                    aria-busy={form.processing}
                >
                    <Search aria-hidden="true" />
                    {form.processing ? 'Mencari...' : 'Cari'}
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
                            <th>Kode Asset</th>
                            <th>Perangkat</th>
                            <th>Kategori</th>
                            <th>Customer</th>
                            <th>Site</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        {assets.data.length > 0 ? (
                            assets.data.map((asset) => (
                                <tr key={asset.id}>
                                    <td className="max-w-xs break-words">
                                        <p className="font-medium">
                                            {asset.asset_code}
                                        </p>
                                        {asset.serial_number && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                SN: {asset.serial_number}
                                            </p>
                                        )}
                                    </td>
                                    <td className="max-w-xs break-words">
                                        {[asset.brand, asset.model]
                                            .filter(Boolean)
                                            .join(' ') || '—'}
                                    </td>
                                    <td className="max-w-xs break-words">
                                        {asset.category}
                                    </td>
                                    <td className="max-w-xs break-words">
                                        {asset.site.customer_name ?? '—'}
                                    </td>
                                    <td className="max-w-xs break-words">
                                        {asset.site.site_name ?? '—'}
                                    </td>
                                    <td>
                                        {asset.status === 'active'
                                            ? 'Aktif'
                                            : asset.status}
                                    </td>
                                    <td>
                                        <Link
                                            href={asset.links.show}
                                            aria-label={`Detail asset ${asset.asset_code}`}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                                size: 'sm',
                                            })}
                                        >
                                            Detail
                                        </Link>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={7} className="empty-state">
                                    {filters.search
                                        ? 'Tidak ada asset yang sesuai dengan pencarian.'
                                        : 'Belum ada data asset.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <nav
                aria-label="Pagination asset"
                className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground"
            >
                <p>
                    {assets.from ?? 0}–{assets.to ?? 0} dari {assets.total}{' '}
                    asset · Halaman {assets.current_page} / {assets.last_page}
                </p>

                <div className="flex gap-2">
                    {assets.prev_page_url ? (
                        <Link
                            preserveScroll
                            href={assets.prev_page_url}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            Sebelumnya
                        </Link>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            Sebelumnya
                        </Button>
                    )}

                    {assets.next_page_url ? (
                        <Link
                            preserveScroll
                            href={assets.next_page_url}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
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

AssetIndex.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Manajemen Asset">
        {page}
    </AuthenticatedLayout>
);
