import type { ReactNode } from 'react';

type Props = { id: string; label: string; error?: string; children: ReactNode; hint?: string };
export function FormField({ id, label, error, children, hint }: Props) {
    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && (
                <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
