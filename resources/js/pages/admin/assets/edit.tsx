import type { ReactNode } from 'react';

import { AssetForm } from '@/components/asset-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { AssetRecord, AssetSiteOption } from '@/types';

type Props = {
    asset: AssetRecord;
    sites: AssetSiteOption[];
    links: {
        show: string;
        update: string;
    };
};

export default function EditAsset({ asset, sites, links }: Props) {
    return (
        <AssetForm
            asset={asset}
            sites={sites}
            action={links.update}
            method="patch"
            cancelHref={links.show}
            submitLabel="Simpan Perubahan"
        />
    );
}

EditAsset.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Edit Asset" description="Perbarui data perangkat dan lokasi customer.">
        {page}
    </AuthenticatedLayout>
);
