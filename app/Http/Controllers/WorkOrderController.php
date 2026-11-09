<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $workOrders = WorkOrder::query()
            ->select([
                'id',
                'wo_number',
                'customer_site_id',
                'title',
                'service_type',
                'priority',
                'status',
                'scheduled_at',
                'created_at',
            ])
            ->with([
                'site:id,customer_id,site_name',
                'site.customer:id,name',
                'technicians:id,name',
            ])
            ->when(
                $user->hasRole('teknisi'),
                fn (Builder $query) => $query->whereHas(
                    'technicians',
                    fn (Builder $technicians) => $technicians->where('users.id', $user->id)
                )
            )
            ->latest('id')
            ->paginate(10)
            ->through(fn (WorkOrder $workOrder): array => [
                'id' => $workOrder->id,
                'number' => $workOrder->wo_number,
                'title' => $workOrder->title,
                'customer' => $workOrder->site?->customer?->name,
                'site' => $workOrder->site?->site_name,
                'service_type' => $workOrder->service_type,
                'priority' => $workOrder->priority,
                'status' => $workOrder->status,
                'scheduled_at' => $workOrder->scheduled_at?->toDateString(),
                'technicians' => $workOrder->technicians->pluck('name')->all(),
            ]);

        return Inertia::render('work-orders/index', [
            'workOrders' => $workOrders,
        ]);
    }
}
