import type { ReactNode } from 'react';

import { CustomerForm } from '@/components/customer-form';
import AuthenticatedLayout from '@/layouts/authenticated-layout';

type Props = { links: { index: string; store: string } };

export default function CreateCustomer({ links }: Props) {
    return <CustomerForm action={links.store} method="post" cancelHref={links.index} submitLabel="Simpan Customer" />;
}

CreateCustomer.layout = (page: ReactNode) => (
    <AuthenticatedLayout title="Tambah Customer" description="Tambahkan data pelanggan dan kontak penagihan.">
        {page}
    </AuthenticatedLayout>
);
