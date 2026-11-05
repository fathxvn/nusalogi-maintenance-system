<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CustomerSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100', 'encoding:UTF-8'],
        ]);

        $search = trim($validated['search'] ?? '');

        $assets = Asset::query()
            ->with([
                'site:id,customer_id,site_name',
                'site.customer:id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('asset_code', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('model', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Asset $asset): array => [
                'id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'category' => $asset->category,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
                'status' => $asset->status,
                'installed_at' => $asset->installed_at?->toDateString(),
                'site' => [
                    'id' => $asset->site?->id,
                    'site_name' => $asset->site?->site_name,
                    'customer_name' => $asset->site?->customer?->name,
                ],
                'links' => [
                    'show' => route('admin.assets.show', $asset),
                ],
            ]);

        return Inertia::render('admin/assets/index', [
            'assets' => $assets,
            'filters' => [
                'search' => $search,
            ],
            'links' => [
                'index' => route('admin.assets.index'),
                'create' => route('admin.assets.create'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        $sites = CustomerSite::query()
            ->select(['id', 'customer_id', 'site_name'])
            ->with('customer:id,name')
            ->orderBy('site_name')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerSite $site): array => [
                'id' => $site->id,
                'site_name' => $site->site_name,
                'customer_name' => $site->customer?->name,
            ]);

        return Inertia::render('admin/assets/create', [
            'sites' => $sites,
            'links' => [
                'index' => route('admin.assets.index'),
                'store' => route('admin.assets.store'),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_site_id' => [
                'bail', 'required', 'integer', 'exists:customer_sites,id',
            ],
            'asset_code' => [
                'bail', 'required', 'string', 'max:50',
                'encoding:UTF-8', 'unique:assets,asset_code',
            ],
            'category' => [
                'bail', 'required', 'string', 'max:100', 'encoding:UTF-8',
            ],
            'brand' => [
                'bail', 'nullable', 'string', 'max:100', 'encoding:UTF-8',
            ],
            'model' => [
                'bail', 'nullable', 'string', 'max:100', 'encoding:UTF-8',
            ],
            'serial_number' => [
                'bail', 'nullable', 'string', 'max:150', 'encoding:UTF-8',
            ],
            'installed_at' => [
                'nullable', 'date_format:Y-m-d',
            ],
        ]);

        Asset::create($validated);

        return redirect()->route('admin.assets.index')
            ->with('success', 'Asset berhasil ditambahkan.');
    }

    public function show(Asset $asset): Response
    {
        $asset->load([
            'site:id,customer_id,site_name',
            'site.customer:id,name',
        ]);

        return Inertia::render('admin/assets/show', [
            'asset' => [
                ...$asset->only([
                    'id',
                    'asset_code',
                    'category',
                    'brand',
                    'model',
                    'serial_number',
                    'status',
                ]),
                'installed_at' => $asset->installed_at?->toDateString(),
                'site' => [
                    'id' => $asset->site?->id,
                    'site_name' => $asset->site?->site_name,
                    'customer_name' => $asset->site?->customer?->name,
                ],
            ],

            'links' => [
                'index' => route('admin.assets.index'),
            ],
        ]);
    }
}
