import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { AssetDeleteAction } from '@/components/asset-delete-action';
import { buttonVariants } from '@/components/ui/button';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { dateLabel } from '@/lib/presentation';
import { cn } from '@/lib/utils';
import type { AssetRecord } from '@/types';

type Props = {
    asset: AssetRecord;
    links: {
        index: string;
        edit: string;
        destroy: string;
    };
};

export default function ShowAsset({ asset, links }: Props) {
    const details = [
        ['Kode Asset', asset.asset_code],
        ['Kategori', asset.category],
        ['Merek', asset.brand],
        ['Model', asset.model],
        ['Nomor Seri', asset.serial_number],
        [
            'Status',
            asset.status === 'active' ? 'Aktif' : asset.status,
        ],
        ['Tanggal Pemasangan', dateLabel(asset.installed_at)],
        ['Customer', asset.site.customer_name],
        ['Site', asset.site.site_name],
    ];

    return (
        <>
            <div className="flex flex-wrap items-center justify-end gap-3">
                <Link
                    href={links.index}
                    className={buttonVariants({
                        variant: 'outline',
                    })}
                >
                    Kembali ke Asset
                </Link>
                <Link
                    href={links.edit}
                    className={cn(buttonVariants(), 'h-10 px-4 hover:bg-primary-hover')}
                >
                    Edit Asset
                </Link>
                <AssetDeleteAction asset={asset} action={links.destroy} className="h-10 px-4 text-sm" />
            </div>

            <section className="panel flex flex-col gap-5" aria-labelledby="asset-details-title">
                <h2 id="asset-details-title" className="section-title">Informasi Asset</h2>
                <dl className="grid gap-5 sm:grid-cols-2">
                    {details.map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-xs font-medium text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="mt-1 text-sm whitespace-pre-line break-words">
                                {value ?? '—'}
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>
        </>
    );
}

ShowAsset.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Detail Asset">
        {page}
    </AuthenticatedLayout>
);
