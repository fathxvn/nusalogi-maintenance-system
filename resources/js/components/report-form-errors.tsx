import { Link } from '@inertiajs/react';
import { reportError } from '@/lib/report';

function messages(value: unknown): string[] {
    if (typeof value === 'string') return [value];
    if (value && typeof value === 'object') return Object.values(value).flatMap(messages);
    return [];
}

export function ReportFormErrors({ errors, refreshUrl }: { errors: Record<string, unknown>; refreshUrl: string }) {
    const errorsToShow = [...new Set(messages(errors))];
    if (!errorsToShow.length) return null;
    return <div role="alert" className="flex flex-col gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
        <ul className="list-inside list-disc text-destructive">{errorsToShow.map(error => <li key={error}>{reportError(error)}</li>)}</ul>
        <Link href={refreshUrl} className="w-fit underline underline-offset-4">Muat ulang data</Link>
    </div>;
}
