<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            ->withQueryString()
            ->through(fn (Customer $customer): array => [
                ...$customer->toArray(),
                'links' => [
                    'show' => route('admin.customers.show', $customer),
                    'edit' => route('admin.customers.edit', $customer),
                    'destroy' => route('admin.customers.destroy', $customer),
                ],
            ]);

        return Inertia::render('admin/customers/index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
            ],
            'links' => [
                'index' => route('admin.customers.index'),
                'create' => route('admin.customers.create'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/customers/create', [
            'links' => [
                'index' => route('admin.customers.index'),
                'store' => route('admin.customers.store'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validatedCustomerData($request));

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer berhasil ditambahkan.');
    }

    public function show(Customer $customer): Response
    {
        $customer->loadCount('sites')->load([
            'sites' => fn (HasMany $query) => $query
                ->select(['id', 'customer_id', 'site_name', 'address', 'pic_name', 'pic_phone'])
                ->orderBy('site_name')->orderBy('id'),
        ]);

        return Inertia::render('admin/customers/show', [
            'customer' => [
                ...$customer->only(['id', 'name', 'pic_name', 'phone', 'email', 'billing_address', 'sites_count']),
                'created_at' => $customer->created_at?->toDateString(),
                'sites' => $customer->sites->toArray(),
            ],
            'links' => [
                'index' => route('admin.customers.index'),
                'edit' => route('admin.customers.edit', $customer),
                'destroy' => route('admin.customers.destroy', $customer),
            ],
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('admin/customers/edit', [
            'customer' => $customer->only(['id', 'name', 'pic_name', 'phone', 'email', 'billing_address']),
            'links' => [
                'index' => route('admin.customers.index'),
                'update' => route('admin.customers.update', $customer),
            ],
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validatedCustomerData($request));

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer berhasil diperbarui.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        try {
            $customer->delete();
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1451) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'customer' => 'Customer tidak dapat dihapus karena masih memiliki data terkait, seperti site atau invoice.',
            ]);
        }

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer berhasil dihapus.');
    }

    /**
     * @return array{name: string, pic_name?: ?string, phone?: ?string, email?: ?string, billing_address?: ?string}
     */
    private function validatedCustomerData(Request $request): array
    {
        return $request->validate([
            'name' => ['bail', 'required', 'string', 'max:150', 'encoding:UTF-8'],
            'pic_name' => ['bail', 'nullable', 'string', 'max:150', 'encoding:UTF-8'],
            'phone' => ['bail', 'nullable', 'string', 'max:30', 'encoding:UTF-8'],
            'email' => ['bail', 'nullable', 'string', 'max:150', 'encoding:UTF-8', 'email'],
            'billing_address' => [
                'bail', 'nullable', 'string', 'encoding:UTF-8',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 65535) {
                        $fail('Alamat penagihan maksimal 65.535 byte.');
                    }
                },
            ],
        ]);
    }
}
