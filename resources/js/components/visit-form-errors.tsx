import { Link } from '@inertiajs/react';

import { visitErrorLabel } from '@/lib/visit-time';

export function VisitFormErrors({ errors, refreshUrl }: { errors: Record<string, string | undefined | Record<string, string>>; refreshUrl: string }) {
    const messages = [...new Set(Object.values(errors).flatMap(value => typeof value === 'string' ? [value] : value ? Object.values(value) : []).filter(Boolean))];
    if (messages.length === 0) return null;

    return (
        <div role="alert" className="flex flex-col gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
            <ul className="list-inside list-disc text-destructive">
                {messages.map(message => <li key={message}>{visitErrorLabel(message)}</li>)}
            </ul>
            <Link href={refreshUrl} className="w-fit underline underline-offset-4">Muat ulang data</Link>
        </div>
    );
}
