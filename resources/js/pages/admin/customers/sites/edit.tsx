import type { ReactNode } from 'react';

import { CustomerSiteForm } from '@/components/customer-site-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { CustomerRecord, CustomerSiteRecord } from '@/types';

type Props = { customer: Pick<CustomerRecord, 'id' | 'name'>; site: CustomerSiteRecord; links: { show: string; update: string } };

export default function EditCustomerSite({ customer, site, links }: Props) {
    return <CustomerSiteForm customer={customer} site={site} action={links.update} method="patch" cancelHref={links.show} submitLabel="Simpan Perubahan" />;
}

EditCustomerSite.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Edit Site" description="Perbarui lokasi pekerjaan dan kontak PIC customer.">
        {page}
    </AuthenticatedLayout>
);
