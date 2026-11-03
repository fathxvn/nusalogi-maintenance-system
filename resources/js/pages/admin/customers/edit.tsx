import type { ReactNode } from 'react';

import { CustomerForm } from '@/components/customer-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';
import type { CustomerRecord } from '@/types';

type Props = { customer: CustomerRecord; links: { index: string; update: string } };

export default function EditCustomer({ customer, links }: Props) {
    return <CustomerForm customer={customer} action={links.update} method="patch" cancelHref={links.index} submitLabel="Simpan Perubahan" />;
}

EditCustomer.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Edit Customer" description="Perbarui data pelanggan dan kontak penagihan.">
        {page}
    </AuthenticatedLayout>
);
