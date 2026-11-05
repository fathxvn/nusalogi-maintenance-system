import type { ReactNode } from 'react';

import { CustomerSiteForm } from '@/components/customer-site-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { CustomerRecord } from '@/types';

type Props = { customer: Pick<CustomerRecord, 'id' | 'name'>; links: { show: string; store: string } };

export default function CreateCustomerSite({ customer, links }: Props) {
    return <CustomerSiteForm customer={customer} action={links.store} method="post" cancelHref={links.show} submitLabel="Simpan Site" />;
}

CreateCustomerSite.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Tambah Site" description="Tambahkan lokasi pekerjaan dan kontak PIC customer.">
        {page}
    </AuthenticatedLayout>
);
