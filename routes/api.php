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
    Route::middleware(['auth:sanctum', 'subscribed'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

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
        });

        Route::apiResource('patients', PatientController::class);

        Route::post('appointments/{appointment}/transition', [AppointmentController::class, 'transition'])->name('api.appointments.transition');
        Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'show']);

        Route::post('visits/{visit}/vitals', [VisitController::class, 'vitals'])->name('api.visits.vitals');
        Route::post('visits/{visit}/clinical', [VisitController::class, 'clinical'])->name('api.visits.clinical');
        Route::post('visits/{visit}/transition', [VisitController::class, 'transition'])->name('api.visits.transition');
        Route::apiResource('visits', VisitController::class)->only(['index', 'store', 'show']);

        // Read-only resources for integration/mobile clients
        Route::apiResource('stock-items', StockItemController::class)->only(['index'])->parameters(['stock-items' => 'stock']);
        Route::get('stock-items/{stock}', [StockItemController::class, 'show'])->name('api.stock-items.show');
        Route::apiResource('lab-orders', LabOrderController::class)->only(['index', 'show'])->parameters(['lab-orders' => 'labOrder']);
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
    });
});
