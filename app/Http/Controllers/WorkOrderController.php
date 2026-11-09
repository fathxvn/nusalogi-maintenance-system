<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CustomerSite;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    private const SERVICE_TYPES = ['install' => 'Instalasi', 'maintenance' => 'Maintenance'];

    private const PRIORITIES = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];

    /** Provisional company numbering: WO- plus a 26-character ULID fits the unique VARCHAR(50) column. */
    private const NUMBER_PREFIX = 'WO-';

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
            'links' => ['create' => $user->hasRole('koordinator') ? route('work-orders.create') : null],
            'assignmentLinks' => $user->hasRole('koordinator')
                ? $workOrders->getCollection()->where('status', 'pending')->mapWithKeys(fn (array $workOrder): array => [
                    $workOrder['id'] => route('work-orders.assignment.edit', $workOrder['id']),
                ])->all()
                : [],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('work-orders/create', [
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
            'links' => ['index' => route('work-orders.index'), 'store' => route('work-orders.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedWorkOrderData($request);

        DB::transaction(function () use ($request, $validated): void {
            $site = CustomerSite::query()->sharedLock()->find($validated['customer_site_id']);

            if (! $site) {
                throw ValidationException::withMessages(['customer_site_id' => 'Customer Site tidak tersedia.']);
            }

            $assetIds = $validated['asset_ids'] ?? [];
            $assets = Asset::query()->whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get(['id', 'customer_site_id']);

            if ($assets->count() !== count($assetIds) || $assets->contains(
                fn (Asset $asset): bool => (int) $asset->customer_site_id !== (int) $site->id
            )) {
                throw ValidationException::withMessages(['asset_ids' => 'Pilih asset yang masih tersedia di Customer Site terpilih.']);
            }

            $workOrder = new WorkOrder([
                ...collect($validated)->except('asset_ids')->all(),
                'wo_number' => self::NUMBER_PREFIX.Str::ulid(),
                'created_by' => $request->user()->id,
                'scheduled_at' => empty($validated['scheduled_at']) ? null : Carbon::createFromFormat(
                    '!Y-m-d\TH:i', $validated['scheduled_at'], config('app.timezone')
                ),
            ]);
            $workOrder->status = 'pending';
            $workOrder->save();
            $workOrder->assets()->attach($assets->modelKeys());
        }, 3);

        return redirect()->route('work-orders.index')->with('success', 'Work Order berhasil ditambahkan.');
    }

    /**
     * @return array{customer_site_id: int|string, title: string, description: string, service_type: string, category: string, priority: string, scheduled_at?: ?string, asset_ids?: list<int|string>}
     */
    private function validatedWorkOrderData(Request $request): array
    {
        return $request->validate([
            'customer_site_id' => ['bail', 'required', 'integer', 'exists:customer_sites,id'],
            'title' => ['bail', 'required', 'string', 'max:150', 'encoding:UTF-8'],
            'description' => [
                'bail', 'required', 'string', 'encoding:UTF-8',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 65535) {
                        $fail('Deskripsi maksimal 65.535 byte.');
                    }
                },
            ],
            'service_type' => ['bail', 'required', 'string', Rule::in(array_keys(self::SERVICE_TYPES))],
            'category' => ['bail', 'required', 'string', 'max:50', 'encoding:UTF-8'],
            'priority' => ['bail', 'required', 'string', Rule::in(array_keys(self::PRIORITIES))],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'asset_ids' => ['sometimes', 'array', 'list'],
            'asset_ids.*' => ['bail', 'required', 'integer', 'distinct', 'exists:assets,id'],
        ]);
    }
}
