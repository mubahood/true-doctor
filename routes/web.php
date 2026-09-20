<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public — marketing
|--------------------------------------------------------------------------
*/
Route::view('/', 'marketing.home')->name('home');
Route::view('/features', 'marketing.features')->name('features');
Route::get('/pricing', [\App\Http\Controllers\MarketingController::class, 'pricing'])->name('pricing');
Route::view('/security', 'marketing.security')->name('security');
Route::get('/contact', [\App\Http\Controllers\MarketingController::class, 'contact'])->name('contact');
Route::post('/contact', [\App\Http\Controllers\MarketingController::class, 'enquire'])->name('contact.send');

// Quote me in the other currency. A POST because it sets a cookie, and a
// GET that changes state is a GET a crawler will change state with.
Route::post('/currency', [\App\Http\Controllers\MarketingController::class, 'currency'])->name('currency');

Route::get('/sitemap.xml', [\App\Http\Controllers\MarketingController::class, 'sitemap'])->name('sitemap');
Route::view('/privacy', 'marketing.privacy')->name('privacy');
Route::view('/terms', 'marketing.terms')->name('terms');

/*
|--------------------------------------------------------------------------
| Staff authentication (single login for all back-office roles)
|--------------------------------------------------------------------------
*/
// `guest` on the GET routes only: somebody already signed in has no business
// on a sign-in form, and sending them to their dashboard is what they wanted
// by following the link. The POSTs stay open so a session that expired between
// rendering the form and submitting it still gets a proper answer.
Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'create'])->name('admin.login');

    // The demonstration door. 404s outside local/demo — see the controller.
    Route::get('/test-login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'createTest'])->name('test-login');

    // Public hospital self-registration (starts a trial)
    Route::get('/register', [\App\Http\Controllers\Auth\RegistrationController::class, 'show'])->name('register');
});

Route::post('/admin/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
Route::post('/admin/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy'])->name('admin.logout');
Route::redirect('/login', '/admin/login')->name('login');
Route::post('/register', [\App\Http\Controllers\Auth\RegistrationController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Admin back-office (tenant)
|--------------------------------------------------------------------------
| HMS modules are added phase by phase per HMS_PLAN.md §7.
*/
/*
|--------------------------------------------------------------------------
| Field Mode
|--------------------------------------------------------------------------
| The client-rendered surface that keeps working when the server cannot be
| reached (docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md §2.1). Outside the admin
| prefix on purpose: it shares the session for the initial load and nothing
| else, and it must be cacheable by the service worker as a static shell.
*/
Route::middleware(['auth', 'verified'])
    ->get('/field', \App\Http\Controllers\FieldModeController::class)
    ->name('field');

Route::prefix('admin')->middleware(['auth', 'admin', 'subscribed', 'onboarding', 'no-store'])->name('admin.')->group(function () {
    Route::get('/', \App\Livewire\Dashboard\Index::class)->name('dashboard');
    Route::get('/onboarding', \App\Livewire\Onboarding\Index::class)->name('onboarding');

    // ── Patients (Phase 1 Step 7 · Phase 3 workspace) ─────────
    // Index, editor and record are Livewire; cards, documents, dependents,
    // insurances and treatments are written by the Patients\Panels\* children.
    // Only the two file streams and the ID-card PDF stay classic GETs.
    Route::get('patients', \App\Livewire\Patients\Index::class)->name('patients.index');
    Route::get('patients/create', \App\Livewire\Patients\Form::class)->name('patients.create');
    Route::get('patients/{patient}/edit', \App\Livewire\Patients\Form::class)->name('patients.edit');
    Route::get('patients/{patient}/id-card', [\App\Http\Controllers\Admin\PatientController::class, 'idCard'])->name('patients.id-card');
    Route::get('patients/{patient}', \App\Livewire\Patients\Show::class)->name('patients.show');

    // Documents (private disk — streamed download behind the Policy)
    Route::get('patients/{patient}/documents/{document}', [\App\Http\Controllers\Admin\PatientDocumentController::class, 'download'])->name('patients.documents.download');

    // Order report attachments (private disk — streamed behind the visit Policy)
    Route::get('orders/{order}/attachments/{attachment}', [\App\Http\Controllers\Admin\OrderAttachmentController::class, 'download'])->name('orders.attachments.download');
    // The same private store, for the work the lab and radiology benches do.
    Route::get('lab-orders/{labOrder}/attachments/{attachment}', [\App\Http\Controllers\Admin\OrderAttachmentController::class, 'lab'])->name('lab-orders.attachments.download');
    Route::get('radiology-orders/{radiologyOrder}/attachments/{attachment}', [\App\Http\Controllers\Admin\OrderAttachmentController::class, 'radiology'])->name('radiology-orders.attachments.download');

    // ── Treatment records (Phase 2 Step 14) ───────────────────
    Route::get('patients/{patient}/treatments/{treatment}', \App\Livewire\Patients\TreatmentShow::class)->name('patients.treatments.show');
    Route::get('patients/{patient}/treatment-photos/{item}', [\App\Http\Controllers\Admin\TreatmentRecordController::class, 'photo'])->name('patients.treatments.photo');

    // ── Staff & org (Phase 1 Step 8) ──────────────────────────
    Route::get('departments', \App\Livewire\Departments\Index::class)->name('departments.index');
    Route::get('rooms', \App\Livewire\Rooms\Index::class)->name('rooms.index');
    Route::get('staff', \App\Livewire\StaffProfiles\Index::class)->name('staff.index');

    // ── Scheduling (Phase 1 Step 9) ───────────────────────────
    Route::get('schedules', \App\Livewire\Schedules\Index::class)->name('schedules.index');

    Route::get('appointments', \App\Livewire\Appointments\Index::class)->name('appointments.index');
    Route::get('appointments/queue', \App\Livewire\Appointments\Queue::class)->name('appointments.queue');
    Route::get('appointments/{appointment}', \App\Livewire\Appointments\Show::class)->name('appointments.show');

    // ── Visits / visits (Phase 1 Step 10) ──────────
    Route::get('visits', \App\Livewire\Visits\Index::class)->name('visits.index');
    Route::get('visits/{visit}', \App\Livewire\Visits\Show::class)->name('visits.show');
    // The whole visit as a PDF — what a patient carries to another hospital.
    Route::get('visits/{visit}/report', [\App\Http\Controllers\Admin\VisitController::class, 'report'])->name('visits.report');

    // ── Billing: price list + billable lines (Phase 1 Step 11a) ──
    Route::get('services', \App\Livewire\Services\Index::class)->name('services.index');

    // ── Billing: invoices + payments (Phase 1 Step 11b) ─────────
    Route::get('invoices', \App\Livewire\Invoices\Index::class)->name('invoices.index');
    Route::get('invoices/{invoice}', \App\Livewire\Invoices\Show::class)->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\Admin\InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('payments/{payment}/receipt', [\App\Http\Controllers\Admin\PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::post('invoices/{invoice}/pay/flutterwave', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'start'])->name('invoices.flutterwave');

    // ── Dispensing (Phase 2 Step 12b) ─────────────────────────
    Route::get('dispensations/{dispensation}', \App\Livewire\Dispensations\Show::class)->name('dispensations.show');

    // ── Pharmacy / inventory (Phase 2 Step 12a) ───────────────
    Route::get('stock/alerts', \App\Livewire\Stock\Alerts::class)->name('stock.alerts');
    // The store's record book: everything in, everything out, and why.
    Route::get('stock/movements', \App\Livewire\Stock\Movements::class)->name('stock.movements');
    Route::get('stock', \App\Livewire\Stock\Index::class)->name('stock.index');
    Route::get('stock/{stock}', \App\Livewire\Stock\Show::class)->name('stock.show');
    Route::get('stock-categories', \App\Livewire\StockCategories\Index::class)->name('stock-categories.index');

    // ── Laboratory (Phase 2 Step 13) ──────────────────────────
    Route::get('lab-tests', \App\Livewire\LabTests\Index::class)->name('lab-tests.index');
    Route::get('lab-orders', \App\Livewire\LabOrders\Index::class)->name('lab-orders.index');
    Route::get('lab-orders/{labOrder}', \App\Livewire\LabOrders\Show::class)->name('lab-orders.show');
    Route::get('lab-orders/{labOrder}/pdf', [\App\Http\Controllers\Admin\LabOrderController::class, 'pdf'])->name('lab-orders.pdf');

    // ── Radiology (Phase 2 Step 13b) ──────────────────────────
    Route::get('radiology-studies', \App\Livewire\RadiologyStudies\Index::class)->name('radiology-studies.index');
    Route::get('radiology-orders', \App\Livewire\RadiologyOrders\Index::class)->name('radiology-orders.index');
    Route::get('radiology-orders/{radiologyOrder}', \App\Livewire\RadiologyOrders\Show::class)->name('radiology-orders.show');
    Route::get('radiology-orders/{radiologyOrder}/pdf', [\App\Http\Controllers\Admin\RadiologyOrderController::class, 'pdf'])->name('radiology-orders.pdf');

    // ── Inpatient / IPD (Phase 3 Step 15) ─────────────────────
    Route::get('wards', \App\Livewire\Wards\Index::class)->name('wards.index');
    Route::get('beds', \App\Livewire\Beds\Index::class)->name('beds.index');
    Route::get('occupancy', \App\Livewire\Admissions\Board::class)->name('admissions.board');
    Route::get('admissions', \App\Livewire\Admissions\Index::class)->name('admissions.index');
    Route::get('admissions/{admission}', \App\Livewire\Admissions\Show::class)->name('admissions.show');
    Route::get('admissions/{admission}/summary', [\App\Http\Controllers\Admin\AdmissionController::class, 'summaryPdf'])->name('admissions.summary');

    // ── Cards (docs/cards.md) ─────────────────────────────────
    // The patient page keeps its Cards panel; this is the same rows without
    // the filter to one patient, for a desk that works by card rather than by
    // person. `{card}` is a uuid — a card number never appears in a URL.
    Route::get('cards', \App\Livewire\Cards\Index::class)->name('cards.index');
    Route::get('cards/{uuid}', \App\Livewire\Cards\Show::class)->name('cards.show');
    Route::get('card-records', \App\Livewire\CardRecords\Index::class)->name('card-records.index');

    // ── Insurance (Phase 4 Step 17) ───────────────────────────
    Route::get('insurance-providers', \App\Livewire\InsuranceProviders\Index::class)->name('insurance-providers.index');
    Route::get('insurance-providers/{insuranceProvider}', \App\Livewire\InsuranceProviders\Show::class)->name('insurance-providers.show');
    Route::get('insurance-providers/{insuranceProvider}/usage', [\App\Http\Controllers\Admin\InsuranceProviderController::class, 'usagePdf'])->name('insurance-providers.usage');
    // Patient coverage is managed by App\Livewire\Patients\Panels\Insurances.
    Route::get('insurance-claims', \App\Livewire\InsuranceClaims\Index::class)->name('insurance-claims.index');
    Route::get('insurance-claims/{insuranceClaim}', \App\Livewire\InsuranceClaims\Show::class)->name('insurance-claims.show');

    // ── Finance / accounting periods (Phase 4 Step 18) ────────
    Route::get('financial-years', \App\Livewire\FinancialYears\Index::class)->name('financial-years.index');
    Route::get('financial-years/{financialYear}', \App\Livewire\FinancialYears\Show::class)->name('financial-years.show');

    // ── Notifications (Phase 4 Step 19) ───────────────────────
    Route::get('notifications', \App\Livewire\Notifications\Index::class)->name('notifications.index');
    Route::post('device-tokens', [\App\Http\Controllers\Admin\DeviceTokenController::class, 'store'])->name('device-tokens.store');

    // ── Reports & dashboards (Phase 4 Step 20) ────────────────
    Route::get('reports', \App\Livewire\Reports\Index::class)->name('reports.index');
    // The same figures as a document, over the same range — a board paper
    // rather than a screen. Opens in its own tab, like every other generated
    // document here.
    Route::get('reports/pdf', [\App\Http\Controllers\Admin\ReportController::class, 'pdf'])->name('reports.pdf');

    // ── Subscription / plan checkout (Step 21) ────────────────
    Route::get('subscription', \App\Livewire\Subscription\Index::class)->name('subscription.index');
    Route::post('subscription/{plan}/checkout', [\App\Http\Controllers\Admin\SubscriptionCheckoutController::class, 'checkout'])->name('subscription.checkout');

    // ── Configuration ─────────────────────────────────────────
    Route::get('settings/hospital', \App\Livewire\Settings\Hospital::class)->name('settings.hospital');
    Route::get('settings/billing', \App\Livewire\Settings\Billing::class)->name('settings.billing');
    Route::get('settings', \App\Livewire\Settings\Site::class)->name('settings.index');

    // Staff/user management (tenant-scoped — see App\Services\StaffService)
    Route::get('users', \App\Livewire\Users\Index::class)->middleware('permission:manage-users')->name('users.index');

    // Which machines hold an offline copy of this hospital's records, and what
    // they have been doing with it (plan §25).
    Route::get('offline-devices', \App\Livewire\Sync\Index::class)
        ->middleware('permission:manage-users')
        ->name('offline-devices.index');

    // "Am I ready to work without a connection?", asked by one clinician about
    // the machine in front of them. No permission beyond being signed in:
    // preparing your own device to do your own job is not an administrative
    // act, and gating it behind `manage-users` would leave the people who
    // actually work offline unable to check.
    Route::get('offline', \App\Livewire\Sync\Readiness::class)->name('offline.readiness');
});

/*
|--------------------------------------------------------------------------
| Super Admin (SaaS central — HMS_PLAN.md §10 convention)
|--------------------------------------------------------------------------
*/
Route::prefix('super')->middleware(['auth', 'super', 'no-store'])->name('super.')->group(function () {
    Route::redirect('/', '/super/hospitals');

    Route::get('hospitals', \App\Livewire\Super\Hospitals\Index::class)->name('hospitals.index');
    Route::get('plans', \App\Livewire\Super\Plans\Index::class)->name('plans.index');
    Route::get('subscriptions', \App\Livewire\Super\Subscriptions\Index::class)->name('subscriptions.index');
});

/*
|--------------------------------------------------------------------------
| Payment gateways (public — no session; authenticity verified server-side)
|--------------------------------------------------------------------------
*/
Route::get('gateway/flutterwave/callback', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'callback'])->name('gateway.callback');
Route::post('gateway/flutterwave/webhook', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'webhook'])->name('gateway.webhook');

// Pesapal — subscription checkout. Both GET: the callback is a browser
// redirect, and the IPN URL is registered with ipn_notification_type "GET"
// (PesapalGateway::ipnId) so it needs no CSRF exemption.
Route::get('gateway/pesapal/callback', [\App\Http\Controllers\Admin\PesapalPaymentController::class, 'callback'])->name('gateway.pesapal.callback');
Route::get('gateway/pesapal/ipn', [\App\Http\Controllers\Admin\PesapalPaymentController::class, 'ipn'])->name('gateway.pesapal.ipn');

require __DIR__.'/auth.php';
