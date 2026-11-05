import type { ReactNode } from 'react';

import { AssetForm } from '@/components/asset-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { AssetSiteOption } from '@/types';

type Props = {
    sites: AssetSiteOption[];
    links: {
        index: string;
        store: string;
    };
};

export default function CreateAsset({ sites, links }: Props) {
    return (
        <AssetForm
            sites={sites}
            action={links.store}
            method="post"
            cancelHref={links.index}
            submitLabel="Simpan Asset"
        />
    );
}

CreateAsset.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Tambah Asset">
        {page}
    </AuthenticatedLayout>
);
