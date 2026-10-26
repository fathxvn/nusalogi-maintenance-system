<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    /**
     * @return array{
     *     metrics: array{active: int, today: int, customers: int, pendingInvoices: int},
     *     trend: list<array{month: string, total: int}>,
     *     statuses: list<array{status: string, total: int}>,
     *     recentWorkOrders: list<array{id: int, number: string, title: string, date: ?string, customer: ?string, site: ?string, status: string, priority: string, technicians: list<string>}>
     * }
     */
    public function forUser(User $user): array
    {
        $workOrders = WorkOrder::query()->where(function (Builder $query) use ($user): void {
            $query->where('created_by', $user->id)
                ->orWhereHas('technicians', fn (Builder $technicians) => $technicians->where('users.id', $user->id));
        });
        $start = now()->startOfMonth()->subMonthsNoOverflow(5);
        $monthlyCounts = (clone $workOrders)
            ->whereBetween('created_at', [$start, now()->endOfMonth()])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')
            ->pluck('total', 'month');
        $trend = [];

        for ($month = 0; $month < 6; $month++) {
            $key = $start->copy()->addMonthsNoOverflow($month)->format('Y-m');
            $trend[] = ['month' => $key, 'total' => (int) ($monthlyCounts[$key] ?? 0)];
        }

        $recent = (clone $workOrders)
            ->select(['id', 'wo_number', 'customer_site_id', 'title', 'status', 'priority', 'scheduled_at', 'created_at'])
            ->with(['site:id,customer_id,site_name', 'site.customer:id,name', 'technicians:id,name'])
            ->latest('created_at')->latest('id')->limit(8)->get()
            ->map(fn (WorkOrder $workOrder): array => [
                'id' => $workOrder->id,
                'number' => $workOrder->wo_number,
                'title' => $workOrder->title,
                'date' => $workOrder->scheduled_at?->toDateString() ?? $workOrder->created_at?->toDateString(),
                'customer' => $workOrder->site?->customer?->name,
                'site' => $workOrder->site?->site_name,
                'status' => $workOrder->status,
                'priority' => $workOrder->priority,
                'technicians' => $workOrder->technicians->pluck('name')->all(),
            ])->all();

        return [
            'metrics' => [
                'active' => (clone $workOrders)->whereIn('status', ['pending', 'assigned', 'in_progress'])->count(),
                'today' => (clone $workOrders)->whereDate('scheduled_at', today())->count(),
                'customers' => (clone $workOrders)->join('customer_sites', 'customer_sites.id', '=', 'work_orders.customer_site_id')
                    ->distinct()->count('customer_sites.customer_id'),
                'pendingInvoices' => Invoice::query()->where('created_by', $user->id)->where('status', 'issued')
                    ->where('grand_total', '>', DB::table('invoice_payments')->selectRaw('COALESCE(SUM(amount), 0)')
                        ->whereColumn('invoice_payments.invoice_id', 'invoices.id'))->count(),
            ],
            'trend' => $trend,
            'statuses' => (clone $workOrders)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')
                ->get()->map(fn (WorkOrder $workOrder): array => ['status' => $workOrder->status, 'total' => (int) $workOrder->total])->all(),
            'recentWorkOrders' => $recent,
        ];
    }
}
