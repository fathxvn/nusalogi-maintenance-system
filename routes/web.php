<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')
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

            Route::patch('/users/{user}/role', [
                UserController::class,
                'updateRole',
            ])->name('users.update-role');

            Route::patch('/users/{user}/status', [
                UserController::class,
                'UpdateStatus',
            ])->name('users.update-status');
        });
});
