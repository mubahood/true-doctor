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

        Route::get('booking/doctors', [AppointmentController::class, 'doctors'])->name('api.booking.doctors');
        Route::get('booking/availability', [AppointmentController::class, 'availability'])->name('api.booking.availability');
        Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('api.appointments.reschedule');
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
        Route::get('visits/{visit}/prescriptions', [\App\Http\Controllers\Api\V1\PrescriptionController::class, 'index'])->name('api.prescriptions.index');
        Route::post('visits/{visit}/prescriptions', [\App\Http\Controllers\Api\V1\PrescriptionController::class, 'store'])->name('api.prescriptions.store');
        Route::post('doses/{record}', [\App\Http\Controllers\Api\V1\PrescriptionController::class, 'markDose'])->name('api.doses.mark');
        // The work on a visit (OrderDesk / OrderService).
        Route::get('visits/{visit}/orders', [\App\Http\Controllers\Api\V1\OrderController::class, 'index'])->name('api.orders.index');
        Route::post('visits/{visit}/orders', [\App\Http\Controllers\Api\V1\OrderController::class, 'store'])->name('api.orders.store');
        Route::get('orders/catalogue', [\App\Http\Controllers\Api\V1\OrderController::class, 'catalogue'])->name('api.orders.catalogue');
        Route::get('orders/lines', [\App\Http\Controllers\Api\V1\OrderController::class, 'lines'])->name('api.orders.lines');
        Route::get('orders/{order}', [\App\Http\Controllers\Api\V1\OrderController::class, 'show'])->name('api.orders.show');
        Route::post('orders/{order}/move', [\App\Http\Controllers\Api\V1\OrderController::class, 'move'])->name('api.orders.move');
        Route::post('orders/{order}/cancel', [\App\Http\Controllers\Api\V1\OrderController::class, 'cancel'])->name('api.orders.cancel');
        Route::put('orders/{order}/report', [\App\Http\Controllers\Api\V1\OrderController::class, 'report'])->name('api.orders.report');
        Route::post('orders/{order}/items', [\App\Http\Controllers\Api\V1\OrderController::class, 'addItem'])->name('api.orders.items.store');
        Route::delete('orders/{order}/items/{item}', [\App\Http\Controllers\Api\V1\OrderController::class, 'removeItem'])->name('api.orders.items.destroy');
        Route::apiResource('visits', VisitController::class)->only(['index', 'store', 'show']);

        // Read-only resources for integration/mobile clients
        Route::get('visits/{visit}/dispensations', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'dispensations'])->name('api.dispensations.index');
        Route::post('visits/{visit}/dispensations', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'dispense'])->name('api.dispensations.store');
        Route::get('stock-items/{stock}/movements', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'movements'])->name('api.stock-items.movements');
        Route::post('stock-items/{stock}/receive', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'receive'])->name('api.stock-items.receive');
        Route::post('stock-items/{stock}/adjust', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'adjust'])->name('api.stock-items.adjust');
        Route::post('stock-items/{stock}/write-off', [\App\Http\Controllers\Api\V1\PharmacyController::class, 'writeOff'])->name('api.stock-items.write-off');
        Route::apiResource('stock-items', StockItemController::class)->only(['index'])->parameters(['stock-items' => 'stock']);
        Route::get('stock-items/{stock}', [StockItemController::class, 'show'])->name('api.stock-items.show');
        Route::get('radiology-orders', [\App\Http\Controllers\Api\V1\RadiologyOrderController::class, 'index'])->name('api.radiology-orders.index');
        Route::get('radiology-orders/{radiologyOrder}', [\App\Http\Controllers\Api\V1\RadiologyOrderController::class, 'show'])->name('api.radiology-orders.show');
        Route::post('radiology-orders/{radiologyOrder}/transition', [\App\Http\Controllers\Api\V1\RadiologyOrderController::class, 'transition'])->name('api.radiology-orders.transition');
        Route::put('radiology-orders/{radiologyOrder}/report', [\App\Http\Controllers\Api\V1\RadiologyOrderController::class, 'report'])->name('api.radiology-orders.report');
        Route::post('lab-orders/{labOrder}/transition', [LabOrderController::class, 'transition'])->name('api.lab-orders.transition');
        Route::apiResource('lab-orders', LabOrderController::class)->only(['index', 'show'])->parameters(['lab-orders' => 'labOrder']);
        Route::get('notifications', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('notifications/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'readAll'])->name('api.notifications.read-all');
        Route::post('notifications/{id}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'read'])->name('api.notifications.read');
        Route::get('reports', [\App\Http\Controllers\Api\V1\ReportController::class, 'index'])->name('api.reports.index');
        Route::get('reports/pdf', [\App\Http\Controllers\Admin\ReportController::class, 'pdf'])->name('api.reports.pdf');
        Route::get('beds', [\App\Http\Controllers\Api\V1\AdmissionController::class, 'beds'])->name('api.beds.index');
        Route::post('admissions/{admission}/transfer', [\App\Http\Controllers\Api\V1\AdmissionController::class, 'transfer'])->name('api.admissions.transfer');
        Route::post('admissions/{admission}/discharge', [\App\Http\Controllers\Api\V1\AdmissionController::class, 'discharge'])->name('api.admissions.discharge');
        Route::get('visits/{visit}/bill', [\App\Http\Controllers\Api\V1\BillingController::class, 'bill'])->name('api.bill.show');
        Route::post('visits/{visit}/invoice', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoice'])->name('api.bill.invoice');
        Route::post('invoices/{invoice}/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'pay'])->name('api.invoices.pay');
        // The same PDFs the web prints, behind the same policies.
        Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\Admin\InvoiceController::class, 'pdf'])->name('api.invoices.pdf');
        Route::get('payments/{payment:uuid}/receipt', [\App\Http\Controllers\Admin\PaymentController::class, 'receipt'])->name('api.payments.receipt');
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
    });
});
