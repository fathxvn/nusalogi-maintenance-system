<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index()
    {
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
            ->latest('id')
            ->paginate(10);

        return Inertia::render('admin/customers/index', [
            'customers' => $customers,
        ]);
    }
}
