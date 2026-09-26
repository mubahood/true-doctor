<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LabOrderController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\VisitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| True-Doctor API v1
|--------------------------------------------------------------------------
| Sanctum-authenticated JSON API. Every response uses the App\Support\ApiResponse
| envelope; RBAC is enforced by the same Policies as the admin panel (C13);
| tenancy by the global scope (ResolveHospital runs after auth via the middleware
| priority list). Built module by module per HMS_PLAN.md §6.
*/
Route::prefix('v1')->group(function () {
    // Public: docs + auth
    Route::get('openapi.json', OpenApiController::class)->name('api.openapi');
    Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');

    // Authenticated
    // `api.account` before `subscribed`: a disabled account is told it is
    // disabled, not that its hospital's subscription ended.
    Route::middleware(['auth:sanctum', 'api.account', 'subscribed'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::post('auth/password', [AuthController::class, 'password'])->name('api.auth.password');

        // What an app needs to draw the system the way the web does:
        // hospital, money format, subscription, menu, statuses.
        Route::get('meta', \App\Http\Controllers\Api\V1\MetaController::class)->name('api.meta');

        // The stat cards at the top of this person's dashboard, as the web draws them.
        Route::get('dashboard', \App\Http\Controllers\Api\V1\DashboardController::class)->name('api.dashboard');

        // ── Offline sync ─────────────────────────────────────────────
        // Dedicated endpoints rather than bending the CRUD ones: batching,
        // per-operation results, idempotency and version checks are all things
        // ordinary REST should not have to carry (plan §9).
        Route::prefix('sync')->name('api.sync.')->group(function () {
            Route::get('status', [SyncController::class, 'status'])->name('status');
            Route::post('register', [SyncController::class, 'register'])->name('register');
            Route::post('push', [SyncController::class, 'push'])->name('push');
            Route::get('pull', [SyncController::class, 'pull'])->name('pull');
            Route::post('ack', [SyncController::class, 'ack'])->name('ack');
            Route::get('reference', [SyncController::class, 'reference'])->name('reference');
            Route::get('operations', [SyncController::class, 'operations'])->name('operations');
            Route::post('resolve', [SyncController::class, 'resolve'])->name('resolve');
        });

        Route::get('patients/{patient}/brief', [PatientController::class, 'brief'])->name('api.patients.brief');
        Route::apiResource('patients', PatientController::class);

        Route::post('appointments/{appointment}/transition', [AppointmentController::class, 'transition'])->name('api.appointments.transition');
        Route::post('appointments/{appointment}/outcome', [AppointmentController::class, 'outcome'])->name('api.appointments.outcome');
        // Today's check-in queue, as the web board shows it.
        Route::get('queue', \App\Http\Controllers\Api\V1\QueueController::class)->name('api.queue');
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);

        Route::post('visits/{visit}/vitals', [VisitController::class, 'vitals'])->name('api.visits.vitals');
        Route::post('visits/{visit}/clinical', [VisitController::class, 'clinical'])->name('api.visits.clinical');
        Route::post('visits/{visit}/transition', [VisitController::class, 'transition'])->name('api.visits.transition');
        Route::post('visits/{visit}/cancel', [VisitController::class, 'cancel'])->name('api.visits.cancel');
        Route::post('visits/intake', [VisitController::class, 'intake'])->name('api.visits.intake');
        // Before the resource, or `phrases` would be taken for a visit's uuid.
        Route::get('visits/phrases', [VisitController::class, 'phrases'])->name('api.visits.phrases');
        Route::get('visits/{visit}/writing-aids', [VisitController::class, 'writingAids'])->name('api.visits.writing-aids');
        Route::apiResource('visits', VisitController::class)->only(['index', 'store', 'show']);

        // Read-only resources for integration/mobile clients
        Route::apiResource('stock-items', StockItemController::class)->only(['index'])->parameters(['stock-items' => 'stock']);
        Route::get('stock-items/{stock}', [StockItemController::class, 'show'])->name('api.stock-items.show');
        Route::apiResource('lab-orders', LabOrderController::class)->only(['index', 'show'])->parameters(['lab-orders' => 'labOrder']);
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
    });
});
