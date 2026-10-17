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
Route::prefix('v1')->middleware('api.app-version')->group(function () {
    // Public: docs + auth
    Route::get('openapi.json', OpenApiController::class)->name('api.openapi');
    Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');
    // The second step when two-step sign-in is on (docs/api/security.md).
    Route::post('auth/two-factor', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'challenge'])->middleware('throttle:12,1')->name('api.auth.two-factor');

    // Self-registration for the app (SaaS only: 404 on a standalone installation).
    // Same RegistrationRequest + RegistrationService + picture check as the web
    // form; 6 sign-ups an hour per IP (the web allows 10). docs/api/extras.md.
    Route::prefix('public')->middleware('edition:saas')->name('api.public.')->group(function () {
        Route::get('plans', [\App\Http\Controllers\Api\V1\PublicRegistrationController::class, 'plans'])->middleware('throttle:30,1')->name('plans');
        Route::get('human-check', [\App\Http\Controllers\Api\V1\PublicRegistrationController::class, 'humanCheck'])->middleware('throttle:30,1')->name('human-check');
        Route::post('register', [\App\Http\Controllers\Api\V1\PublicRegistrationController::class, 'register'])->middleware('throttle:6,60')->name('register');
        // The app's "Try the demo" door: the server signs a visitor in as a demo role (docs/api/demo.md).
        Route::get('demo', [\App\Http\Controllers\Api\V1\DemoController::class, 'show'])->middleware('throttle:30,1')->name('demo');
        Route::post('demo/login', [\App\Http\Controllers\Api\V1\DemoController::class, 'login'])->middleware('throttle:12,1')->name('demo.login');
    });

    // Authenticated
    // `api.conditional` tags every successful GET with a weak ETag and answers
    // `If-None-Match` with 304 (docs/api/plane.md). It only touches safe methods.
    // `api.account` before `subscribed`: a disabled account is told it is
    // disabled, not that its hospital's subscription ended.
    Route::middleware(['auth:sanctum', 'api.account', 'subscribed', 'idempotent', 'api.conditional'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::post('auth/password', [AuthController::class, 'password'])->name('api.auth.password');
        Route::post('auth/two-factor/setup', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'setup'])->name('api.auth.two-factor.setup');
        Route::post('auth/two-factor/confirm', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'confirm'])->middleware('throttle:12,1')->name('api.auth.two-factor.confirm');
        Route::get('auth/devices', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'devices'])->name('api.auth.devices');
        Route::delete('auth/devices/{device}', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'revoke'])->whereNumber('device')->name('api.auth.devices.revoke');
        // The dental chart and treatment plan (docs/api/dental.md).
        Route::get('patients/{patient}/dental', [\App\Http\Controllers\Api\V1\DentalController::class, 'show'])->name('api.dental.show');
        Route::post('patients/{patient}/dental/findings', [\App\Http\Controllers\Api\V1\DentalController::class, 'record'])->name('api.dental.findings');
        Route::post('patients/{patient}/dental/plan', [\App\Http\Controllers\Api\V1\DentalController::class, 'plan'])->name('api.dental.plan');
        Route::post('dental/plan/{item}/status', [\App\Http\Controllers\Api\V1\DentalController::class, 'status'])->name('api.dental.status');
        Route::put('patients/{patient}/recall', [\App\Http\Controllers\Api\V1\DentalController::class, 'recall'])->name('api.dental.recall');
        Route::get('patients/{patient}/dental/quote', \App\Http\Controllers\Admin\DentalQuoteController::class)->name('api.dental.quote');
        Route::get('dental/report', [\App\Http\Controllers\Api\V1\DentalController::class, 'report'])->name('api.dental.report');

        // Patient links (roadmap 05 §4, docs/api/patient-links.md): send a result, summary,
        // prescription or quote by link; list, read the open log, withdraw.
        Route::get('patient-links', [\App\Http\Controllers\Api\V1\PatientLinkController::class, 'index'])->name('api.patient-links.index');
        Route::post('patient-links', [\App\Http\Controllers\Api\V1\PatientLinkController::class, 'store'])->middleware('throttle:30,1')->name('api.patient-links.store');
        Route::get('patient-links/{patientLink}', [\App\Http\Controllers\Api\V1\PatientLinkController::class, 'show'])->whereUuid('patientLink')->name('api.patient-links.show');
        Route::delete('patient-links/{patientLink}', [\App\Http\Controllers\Api\V1\PatientLinkController::class, 'destroy'])->whereUuid('patientLink')->name('api.patient-links.destroy');

        // Coded diagnoses (docs/api/diagnoses.md).
        Route::get('diagnoses/search', [\App\Http\Controllers\Api\V1\DiagnosisController::class, 'search'])->name('api.diagnoses.search');
        Route::get('visits/{visit}/diagnoses', [\App\Http\Controllers\Api\V1\DiagnosisController::class, 'index'])->name('api.diagnoses.index');
        Route::post('visits/{visit}/diagnoses', [\App\Http\Controllers\Api\V1\DiagnosisController::class, 'store'])->name('api.diagnoses.store');
        Route::patch('visits/{visit}/diagnoses/{id}', [\App\Http\Controllers\Api\V1\DiagnosisController::class, 'update'])->whereNumber('id')->name('api.diagnoses.update');
        Route::delete('visits/{visit}/diagnoses/{id}', [\App\Http\Controllers\Api\V1\DiagnosisController::class, 'destroy'])->whereNumber('id')->name('api.diagnoses.destroy');

        // Audit log: changes, and who opened a record (roadmap 07 §3, docs/api/audit.md).
        Route::get('audit-log', [\App\Http\Controllers\Api\V1\AuditController::class, 'changes'])->name('api.audit.changes');
        Route::get('patients/{patient}/access-log', [\App\Http\Controllers\Api\V1\AuditController::class, 'access'])->name('api.audit.access');

        // Crashes the app recorded, for the error list (docs/api/security.md).
        Route::post('diagnostics/errors', \App\Http\Controllers\Api\V1\ErrorReportController::class)->middleware('throttle:10,1')->name('api.diagnostics.errors');
        Route::post('auth/sign-out-everywhere', [\App\Http\Controllers\Api\V1\AccountSecurityController::class, 'signOutEverywhere'])->name('api.auth.sign-out-everywhere');

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
        // Refunds and credit notes (roadmap 04 §8, docs/api/refunds.md).
        Route::get('credits', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'index'])->name('api.credits.index');
        Route::get('invoices/{invoice}/credits', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'forInvoice'])->name('api.invoices.credits');
        Route::post('invoices/{invoice}/credit-notes', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'creditNote'])->name('api.invoices.credit-notes');
        Route::post('invoices/{invoice}/refunds', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'refund'])->name('api.invoices.refunds');
        Route::post('credits/{credit}/approve', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'approve'])->name('api.credits.approve');
        Route::post('credits/{credit}/reject', [\App\Http\Controllers\Api\V1\InvoiceCreditController::class, 'reject'])->name('api.credits.reject');
        Route::get('credits/{credit}/pdf', [\App\Http\Controllers\Admin\InvoiceCreditController::class, 'pdf'])->name('api.credits.pdf');

        // The rest of the back office, one file per area (docs/api.md).
        foreach (['care', 'pharmacy', 'finance', 'admin', 'extras', 'plane', 'notify', 'journeys'] as $area) {
            require __DIR__."/api/{$area}.php";
        }
    });
});
