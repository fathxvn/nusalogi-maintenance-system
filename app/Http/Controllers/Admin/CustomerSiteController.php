<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSite;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerSiteController extends Controller
{
    public function create(Customer $customer): Response
    {
        return Inertia::render('admin/customers/sites/create', [
            'customer' => $customer->only(['id', 'name']),
            'links' => [
                'show' => route('admin.customers.show', $customer),
                'store' => route('admin.customers.sites.store', $customer),
            ],
        ]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $customer->sites()->create($this->validatedSiteData($request));

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Site berhasil ditambahkan.');
    }

    public function edit(Customer $customer, CustomerSite $site): Response
    {
        return Inertia::render('admin/customers/sites/edit', [
            'customer' => $customer->only(['id', 'name']),
            'site' => $site->only(['id', 'site_name', 'address', 'pic_name', 'pic_phone']),
            'links' => [
                'show' => route('admin.customers.show', $customer),
                'update' => route('admin.customers.sites.update', [$customer, $site]),
            ],
        ]);
    }

    public function update(Request $request, Customer $customer, CustomerSite $site): RedirectResponse
    {
        $site->update($this->validatedSiteData($request));

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Site berhasil diperbarui.');
    }

    /**
     * @return array{site_name: string, address: string, pic_name?: ?string, pic_phone?: ?string}
     */
    private function validatedSiteData(Request $request): array
    {
        return $request->validate([
            'site_name' => ['bail', 'required', 'string', 'max:150', 'encoding:UTF-8'],
            'address' => [
                'bail', 'required', 'string', 'encoding:UTF-8',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 65535) {
                        $fail('Alamat site maksimal 65.535 byte.');
                    }
                },
            ],
            'pic_name' => ['bail', 'nullable', 'string', 'max:150', 'encoding:UTF-8'],
            'pic_phone' => ['bail', 'nullable', 'string', 'max:30', 'encoding:UTF-8'],
        ]);
    }
}
