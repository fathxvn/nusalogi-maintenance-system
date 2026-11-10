<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CustomerSite;
use App\Models\ServiceTicket;
use App\Models\WorkOrder;
use App\Services\CreateWorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    private const SERVICE_TYPES = ['install' => 'Instalasi', 'maintenance' => 'Maintenance'];

    private const PRIORITIES = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $workOrders = WorkOrder::query()
            ->visibleTo($user)
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
            'links' => ['create' => $user->hasRole('koordinator') ? route('work-orders.create') : null],
            'assignmentLinks' => $user->hasRole('koordinator')
                ? $workOrders->getCollection()->where('status', 'pending')->mapWithKeys(fn (array $workOrder): array => [
                    $workOrder['id'] => route('work-orders.assignment.edit', $workOrder['id']),
                ])->all()
                : [],
        ]);
    }

    public function create(Request $request): Response
    {
        $ticket = $request->filled('ticket_id')
            ? ServiceTicket::query()->findOrFail($request->integer('ticket_id'))
            : null;
        $m1 = config('m1.writer_mode') === 'm1' || $request->boolean('m1') || $ticket !== null;

        return Inertia::render('work-orders/create', [
            'm1' => $m1 ? [
                'command_key' => (string) Str::uuid(),
                'ticket_id' => $ticket?->id,
                'ticket_version' => $ticket?->lock_version,
                'site_id' => $ticket?->site_id,
                'ticket_number' => $ticket?->ticket_number,
            ] : null,
            'm1CreateUrl' => route('work-orders.create', ['m1' => 1]),
            'sites' => CustomerSite::query()
                ->select(['id', 'customer_id', 'site_name'])
                ->with(['customer:id,name', 'assets:id,customer_site_id,asset_code,category'])
                ->orderBy('site_name')->orderBy('id')->get()
                ->map(fn (CustomerSite $site): array => [
                    'id' => $site->id,
                    'site_name' => $site->site_name,
                    'customer_name' => $site->customer?->name,
                    'assets' => $site->assets->sortBy('asset_code')->values()->map(fn (Asset $asset): array => $asset->only(['id', 'asset_code', 'category']))->all(),
                ])->all(),
            'serviceTypes' => self::SERVICE_TYPES,
            'priorities' => self::PRIORITIES,
            'scheduleTimezone' => config('app.timezone'),
            'links' => ['index' => route('work-orders.index'), 'store' => route($m1 ? 'work-orders.m1.store' : 'work-orders.store')],
        ]);
    }

    public function store(Request $request, CreateWorkOrderService $service): RedirectResponse
    {
        $service->create($request->user(), $request->all(), true);

        return redirect()->route('work-orders.index')->with('success', 'Work Order berhasil ditambahkan.');
    }

    public function storeM1(Request $request, CreateWorkOrderService $service): RedirectResponse
    {
        $service->create($request->user(), $request->all());

        return redirect()->route('work-orders.index')->with('success', 'Work Order berhasil ditambahkan.');
    }
}
