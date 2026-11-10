<?php

use App\Http\Controllers\Admin\AssetController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerSiteController;
use App\Http\Controllers\Admin\UserApprovalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServiceTicketController;
use App\Http\Controllers\WorkOrderAssignmentController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkVisitController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest:web')->group(function () {
    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])
        ->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('auth.google.callback');
});

Route::middleware('auth')->group(function () {
    Route::middleware('role:admin,koordinator')->group(function (): void {
        Route::get('/tickets', [ServiceTicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [ServiceTicketController::class, 'create'])->middleware('role:admin')->name('tickets.create');
        Route::post('/tickets', [ServiceTicketController::class, 'store'])->middleware('role:admin')->name('tickets.store');
        Route::get('/tickets/{ticket}', [ServiceTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/triage', [ServiceTicketController::class, 'triage'])->middleware('role:koordinator')->name('tickets.triage');
        Route::post('/tickets/{ticket}/approve', [ServiceTicketController::class, 'approve'])->middleware('role:koordinator')->name('tickets.approve');
    });
    Route::get('/dashboard', DashboardController::class)
        ->middleware('role:admin,koordinator,teknisi')
        ->name('dashboard');

    Route::get('/work-orders', [WorkOrderController::class, 'index'])
        ->middleware('role:admin,koordinator,teknisi')
        ->name('work-orders.index');

    Route::get('/work-orders/{workOrder}/visits', [WorkVisitController::class, 'index'])
        ->middleware('role:admin,koordinator,teknisi')->name('work-orders.visits.index');

    Route::middleware('role:koordinator')->group(function (): void {
        Route::get('/work-orders/{workOrder}/visits/create', [WorkVisitController::class, 'create'])->name('work-orders.visits.create');
        Route::post('/work-orders/{workOrder}/visits', [WorkVisitController::class, 'store'])->name('work-orders.visits.store');
        Route::post('/work-orders/{workOrder}/visits/{visit}/confirm', [WorkVisitController::class, 'confirm'])->whereNumber('visit')->name('work-orders.visits.confirm');
        Route::post('/work-orders/{workOrder}/visits/{visit}/bookings/{booking}/cancel', [WorkVisitController::class, 'cancel'])->whereNumber('visit')->whereNumber('booking')->name('work-orders.visits.bookings.cancel');
    });

    Route::middleware('role:koordinator')->group(function () {
        Route::post('/work-orders/m1', [WorkOrderController::class, 'storeM1'])->name('work-orders.m1.store');
        Route::get(
            '/work-orders/{workOrder}/assign',
            [WorkOrderAssignmentController::class, 'edit']
        )->name('work-orders.assignment.edit');

        Route::patch(
            '/work-orders/{workOrder}/assignment',
            [WorkOrderAssignmentController::class, 'update']
        )->name('work-orders.assignment.update');

        Route::get('/work-orders/create', [WorkOrderController::class, 'create'])->name('work-orders.create');
        Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    });

    Route::get('/migration-test', function (Request $request) {
        return Inertia::render('migration-test', [
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
        ]);
    })->name('migration.test');

    Route::middleware('role:admin')
        ->get('/admin/check', function () {
            return 'Admin authorized';
        })
        ->name('admin.check');

    Route::middleware('role:admin')
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::get('/users', [UserController::class, 'index'])
                ->name('users.index');

            Route::get('/users/create', [UserController::class, 'create'])
                ->name('users.create');

            Route::post('/users', [UserController::class, 'store'])
                ->name('users.store');

            Route::get('/users/{user}/edit', [
                UserController::class,
                'edit',
            ])->name('users.edit');

            Route::patch('/users/{user}/approve', [UserApprovalController::class, 'approve'])
                ->name('users.approve');

            Route::patch('/users/{user}/reject', [UserApprovalController::class, 'reject'])
                ->name('users.reject');

            Route::patch('/users/{user}/role', [
                UserController::class,
                'updateRole',
            ])->name('users.update-role');

            Route::patch('/users/{user}/status', [
                UserController::class,
                'UpdateStatus',
            ])->name('users.update-status');

            Route::get('/customers', [CustomerController::class, 'index'])
                ->name('customers.index');

            Route::get('/customers/create', [CustomerController::class, 'create'])
                ->name('customers.create');

            Route::post('/customers', [CustomerController::class, 'store'])
                ->name('customers.store');

            Route::get('/customers/{customer}', [CustomerController::class, 'show'])
                ->name('customers.show');

            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])
                ->name('customers.edit');

            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
                ->name('customers.update');

            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
                ->name('customers.destroy');

            Route::get('/customers/{customer}/sites/create', [CustomerSiteController::class, 'create'])
                ->name('customers.sites.create');

            Route::post('/customers/{customer}/sites', [CustomerSiteController::class, 'store'])
                ->name('customers.sites.store');

            Route::get('/customers/{customer}/sites/{site}/edit', [CustomerSiteController::class, 'edit'])
                ->scopeBindings()
                ->name('customers.sites.edit');

            Route::patch('/customers/{customer}/sites/{site}', [CustomerSiteController::class, 'update'])
                ->scopeBindings()
                ->name('customers.sites.update');

            Route::get('/assets', [AssetController::class, 'index'])
                ->name('assets.index');

            Route::get('/assets/create', [AssetController::class, 'create'])
                ->name('assets.create');

            Route::post('/assets', [AssetController::class, 'store'])
                ->name('assets.store');

            Route::get('/assets/{asset}', [AssetController::class, 'show'])
                ->name('assets.show');

            Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])
                ->name('assets.edit');

            Route::patch('/assets/{asset}', [AssetController::class, 'update'])
                ->name('assets.update');

            Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])
                ->name('assets.destroy');
        });
});
