<?php

use App\Http\Controllers\Admin\AssetController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerSiteController;
use App\Http\Controllers\Admin\UserApprovalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\DashboardController;
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
    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

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
