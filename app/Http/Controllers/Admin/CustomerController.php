<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        // Validasi input pencarian
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100', 'encoding:UTF-8'],
        ]);

        $search = trim($validated['search'] ?? '');

        // Query customer
        $customers = Customer::query()
            ->select([
                'id',
                'name',
                'pic_name',
                'phone',
                'email',
                'billing_address',
                'created_at',
            ])
            ->withCount('sites')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('pic_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('admin/customers/index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
            ],
            'links' => [
                'index' => route('admin.customers.index'),
            ],
        ]);
    }
}
