import { useId, useState } from 'react';
import { distributionPercentages } from '@/lib/chart-data';
import { workOrderLabels } from '@/lib/presentation';
import type { DashboardProps } from '@/types';

const monthLabel = (month: string) =>
    new Intl.DateTimeFormat('id-ID', { month: 'short' }).format(new Date(`${month}-01T12:00:00`));
export function WorkOrderTrend({ data }: { data: DashboardProps['trend'] }) {
    const id = useId();
    const [focused, setFocused] = useState<number | null>(null);
    const maximum = Math.max(1, ...data.map((point) => point.total));
    const points = data.map((point, index) => ({
        ...point,
        x: 48 + (index * 500) / Math.max(1, data.length - 1),
        y: 174 - (point.total / maximum) * 138,
    }));
    const selected = focused === null ? null : points[focused];
    return (
        <section className="panel min-w-0" aria-labelledby={`${id}-title`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id={`${id}-title`} className="section-title">
                        Tren Work Order
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        WO dibuat · enam bulan terakhir
                    </p>
                </div>
                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span className="h-0.5 w-5 bg-primary" />
                    Work Order
                </span>
            </div>
            {data.some((point) => point.total > 0) ? (
                <div className="mt-5">
                    <p
                        role="status"
                        aria-live="polite"
                        className="h-5 text-right text-xs text-muted-foreground"
                    >
                        {selected
                            ? `${monthLabel(selected.month)}: ${selected.total} Work Order`
                            : 'Arahkan atau fokuskan titik untuk detail'}
                    </p>
                    <svg viewBox="0 0 580 208" className="w-full" aria-labelledby={`${id}-title`}>
                        <desc>
                            {data.map((point) => `${point.month}: ${point.total}`).join('; ')}
                        </desc>
                        {[0, 0.5, 1].map((fraction) => (
                            <g key={fraction}>
                                <line
                                    x1="48"
                                    x2="548"
                                    y1={174 - fraction * 138}
                                    y2={174 - fraction * 138}
                                    stroke="var(--border)"
                                    strokeDasharray="3 5"
                                />
                                <text
                                    x="36"
                                    y={178 - fraction * 138}
                                    textAnchor="end"
                                    fill="var(--muted-foreground)"
                                    fontSize="11"
                                >
                                    {Number((maximum * fraction).toFixed(1))}
                                </text>
                            </g>
                        ))}
                        <polyline
                            points={points.map((point) => `${point.x},${point.y}`).join(' ')}
                            fill="none"
                            stroke="var(--primary)"
                            strokeWidth="2.5"
                            strokeLinejoin="round"
                        />
                        {points.map((point, index) => (
                            <g key={point.month}>
                                <text
                                    x={point.x}
                                    y="202"
                                    textAnchor="middle"
                                    fill="var(--muted-foreground)"
                                    fontSize="11"
                                >
                                    {monthLabel(point.month)}
                                </text>
                                <circle
                                    cx={point.x}
                                    cy={point.y}
                                    r="5"
                                    fill="var(--card)"
                                    stroke="var(--primary)"
                                    strokeWidth="2"
                                    tabIndex={0}
                                    aria-label={`${point.month}: ${point.total} Work Order`}
                                    onMouseEnter={() => setFocused(index)}
                                    onMouseLeave={() => setFocused(null)}
                                    onFocus={() => setFocused(index)}
                                    onBlur={() => setFocused(null)}
                                    className="outline-none focus:stroke-[4]"
                                >
                                    <title>
                                        {monthLabel(point.month)}: {point.total} Work Order
                                    </title>
                                </circle>
                            </g>
                        ))}
                    </svg>
                </div>
            ) : (
                <p className="empty-state">Belum ada Work Order pada periode ini.</p>
            )}
        </section>
    );
}

export function WorkOrderDistribution({ data }: { data: DashboardProps['statuses'] }) {
    const id = useId();
    const total = data.reduce((sum, item) => sum + item.total, 0);
    const colors = ['#627f77', '#738d9b', '#a18a61', '#8b869a', '#8f9894'];
    const slices = data.map((item, index) => ({
        ...item,
        color: colors[index % colors.length],
        fraction: total ? item.total / total : 0,
    }));
    const percentages = distributionPercentages(data.map((item) => item.total));
    let offset = 0;
    return (
        <section className="panel" aria-labelledby={`${id}-title`}>
            <h2 id={`${id}-title`} className="section-title">
                Status Work Order
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
                Distribusi seluruh pekerjaan dalam cakupan Anda
            </p>
            {total ? (
                <div className="mt-5 flex flex-wrap items-center gap-6">
                    <svg
                        viewBox="0 0 120 120"
                        className="size-36 shrink-0"
                        role="img"
                        aria-label={`${total} Work Order menurut status`}
                    >
                        {slices.map((item) => {
                            const start = offset;
                            offset += item.fraction * 100;
                            return (
                                <circle
                                    key={item.status}
                                    cx="60"
                                    cy="60"
                                    r="46"
                                    fill="none"
                                    stroke={item.color}
                                    strokeWidth="12"
                                    pathLength="100"
                                    strokeDasharray={`${item.fraction * 100} ${100 - item.fraction * 100}`}
                                    strokeDashoffset={-start}
                                    transform="rotate(-90 60 60)"
                                />
                            );
                        })}
                        <text
                            x="60"
                            y="59"
                            textAnchor="middle"
                            fill="var(--foreground)"
                            fontSize="24"
                            fontWeight="600"
                        >
                            {total}
                        </text>
                        <text
                            x="60"
                            y="76"
                            textAnchor="middle"
                            fill="var(--muted-foreground)"
                            fontSize="10"
                        >
                            Work Order
                        </text>
                    </svg>
                    <ul className="flex min-w-40 flex-1 flex-col gap-3">
                        {slices.map((item, index) => (
                            <li key={item.status} className="flex items-center gap-2 text-xs">
                                <span
                                    className="size-2 rounded-full"
                                    style={{ background: item.color }}
                                    aria-hidden="true"
                                />
                                <span className="flex-1">
                                    {workOrderLabels[item.status] ?? item.status}
                                </span>
                                <span className="text-muted-foreground tabular-nums">
                                    {item.total} · {percentages[index].toFixed(1)}%
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="empty-state">Belum ada distribusi status untuk ditampilkan.</p>
            )}
        </section>
    );
}
