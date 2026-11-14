import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { ReportFormErrors } from '@/components/report-form-errors';
import type { ReportAction } from '@/lib/report';

type Props = { action: ReportAction; workOrderVersion: number; reportVersion?: number; label: string; refreshUrl: string; description?: string };
export function ReportCommandForm({ action, workOrderVersion, reportVersion, label, refreshUrl, description }: Props) {
    const form = useForm({ command_key: action.command_key, expected_work_order_version: workOrderVersion,
        ...(reportVersion !== undefined ? { expected_report_version: reportVersion } : {}) });
    const submitting = useRef(false);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing || submitting.current || !action.url || !action.command_key) return;
        submitting.current = true;
        form.post(action.url, { preserveScroll: true, preserveState: 'errors', errorBag: `report-${action.command_key}`, onFinish: () => { submitting.current = false; } });
    }
    return <form onSubmit={submit} aria-label={label} className="flex flex-col items-start gap-3">
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
        <ReportFormErrors errors={form.errors} refreshUrl={refreshUrl} />
        <Button type="submit" disabled={form.processing || !action.url || !action.command_key} aria-busy={form.processing}>
            {form.processing ? 'Menyimpan…' : label}
        </Button>
    </form>;
}
