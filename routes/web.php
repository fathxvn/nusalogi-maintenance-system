<?php

use App\Http\Controllers\Admin\AssetController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerSiteController;
use App\Http\Controllers\Admin\UserApprovalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\BastController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportEvidenceController;
use App\Http\Controllers\ServiceReportController;
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

    Route::get('/work-orders/{workOrder}/acceptance', [BastController::class, 'index'])->middleware('role:admin,koordinator,teknisi')->name('work-orders.acceptance.index');
    Route::get('/bast/{version}', [BastController::class, 'show'])->middleware('role:admin,koordinator,teknisi')->whereNumber('version')->name('bast.show');
    Route::get('/bast/{version}/files/{purpose}', [BastController::class, 'download'])->middleware('role:admin,koordinator,teknisi')->whereNumber('version')->name('bast.download');
    Route::middleware('role:koordinator')->group(function (): void {
        Route::post('/work-orders/{workOrder}/acceptance', [BastController::class, 'store'])->name('work-orders.acceptance.store');
        Route::patch('/bast/{version}', [BastController::class, 'update'])->whereNumber('version')->name('bast.update');
        Route::post('/bast/{version}/issue', [BastController::class, 'issue'])->whereNumber('version')->name('bast.issue');
        Route::post('/bast/{version}/decide', [BastController::class, 'decide'])->whereNumber('version')->name('bast.decide');
        Route::post('/bast/{version}/cancel', [BastController::class, 'cancel'])->whereNumber('version')->name('bast.cancel');
        Route::post('/bast/{version}/revise', [BastController::class, 'revise'])->whereNumber('version')->name('bast.revise');
        Route::post('/work-orders/{workOrder}/reports/{report}/correction', [ServiceReportController::class, 'correction'])->whereNumber('report')->name('work-orders.reports.correction');
    });

    Route::get('/work-orders/{workOrder}/reports', [ServiceReportController::class, 'index'])
        ->middleware('role:admin,koordinator,teknisi')->name('work-orders.reports.index');
    Route::get('/work-orders/{workOrder}/reports/{report}', [ServiceReportController::class, 'show'])
        ->middleware('role:admin,koordinator,teknisi')->whereNumber('report')->name('work-orders.reports.show');
    Route::middleware('role:teknisi')->group(function (): void {
        Route::post('/work-orders/{workOrder}/scopes/{scope}/reports', [ServiceReportController::class, 'store'])->whereNumber('scope')->name('work-orders.reports.store');
        Route::patch('/work-orders/{workOrder}/reports/{report}', [ServiceReportController::class, 'update'])->whereNumber('report')->name('work-orders.reports.update');
        Route::post('/work-orders/{workOrder}/reports/{report}/submit', [ServiceReportController::class, 'submit'])->whereNumber('report')->name('work-orders.reports.submit');
        Route::post('/work-orders/{workOrder}/reports/{report}/revise', [ServiceReportController::class, 'revise'])->whereNumber('report')->name('work-orders.reports.revise');
        Route::post('/work-orders/{workOrder}/reports/{report}/materials', [ServiceReportController::class, 'material'])->whereNumber('report')->name('work-orders.reports.materials.store');
    });
    Route::post('/work-orders/{workOrder}/reports/{report}/review', [ServiceReportController::class, 'review'])
        ->middleware('role:koordinator')->whereNumber('report')->name('work-orders.reports.review');

    Route::post('/work-orders/{workOrder}/reports/{report}/evidence', [ReportEvidenceController::class, 'store'])
        ->middleware('role:teknisi')->whereNumber('report')->name('work-orders.reports.evidence.store');
    Route::get('/work-orders/{workOrder}/reports/{report}/evidence/{photo}', [ReportEvidenceController::class, 'show'])
        ->middleware('role:admin,koordinator,teknisi')->whereNumber('report')->whereNumber('photo')->name('work-orders.reports.evidence.show');

    Route::middleware('role:koordinator')->group(function (): void {
        Route::get('/work-orders/{workOrder}/visits/create', [WorkVisitController::class, 'create'])->name('work-orders.visits.create');
        Route::post('/work-orders/{workOrder}/visits', [WorkVisitController::class, 'store'])->name('work-orders.visits.store');
        Route::post('/work-orders/{workOrder}/visits/{visit}/confirm', [WorkVisitController::class, 'confirm'])->whereNumber('visit')->name('work-orders.visits.confirm');
        Route::post('/work-orders/{workOrder}/visits/{visit}/reschedule', [WorkVisitController::class, 'reschedule'])->whereNumber('visit')->name('work-orders.visits.reschedule');
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

    Route::middleware('role:teknisi')->group(function (): void {
        Route::post(
            '/work-orders/{workOrder}/visits/{visit}/bookings/{booking}/start',
            [WorkVisitController::class, 'start'],
        )
            ->whereNumber('visit')
            ->whereNumber('booking')
            ->name('work-orders.visits.bookings.start');

        Route::post(
            '/work-orders/{workOrder}/visits/{visit}/bookings/{booking}/complete',
            [WorkVisitController::class, 'complete'],
        )
            ->whereNumber('visit')
            ->whereNumber('booking')
            ->name('work-orders.visits.bookings.complete');
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
