<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')
        ->name('dashboard');

    Route::middleware('role:admin')
        ->get('/admin/check', function () {
            return 'Admin authorized';
        })
        ->name('admin.check');
});
