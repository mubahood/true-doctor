<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public — marketing
|--------------------------------------------------------------------------
*/
/*
 * Public pages carry the landing-traffic middleware: it records where every
 * arrival came from and sends an ad click on to the page its sitelink named.
 * See App\Http\Middleware\HandleLandingTraffic.
 */
Route::get('/', \App\Http\Controllers\EditionHomeController::class)->middleware('landing')->name('home');

Route::middleware(['edition:saas', 'landing'])->group(function () {
    Route::view('/features', 'marketing.features')->name('features');

    // `/product` is the address used in the ad campaign; `/features` is the
    // one the site has always used and the one in the sitemap. An alias
    // rather than a rename, so no existing link breaks — and a redirect
    // rather than a second page, so there is one canonical address.
    Route::redirect('/product', '/features', 302)->name('product');

    Route::get('/pricing', [\App\Http\Controllers\MarketingController::class, 'pricing'])->name('pricing');
    Route::view('/security', 'marketing.security')->name('security');

    // Pages that are content in config/landing.php (PublicPageController).
    Route::get('/for/{practice}', [\App\Http\Controllers\PublicPageController::class, 'practice'])->where('practice', '[a-z-]+')->name('for');
    Route::get('/walkthrough', [\App\Http\Controllers\PublicPageController::class, 'walkthrough'])->name('walkthrough');
    Route::get('/get-app', [\App\Http\Controllers\PublicPageController::class, 'app'])->name('get-app');
    Route::get('/about', [\App\Http\Controllers\PublicPageController::class, 'about'])->name('about');
    Route::get('/demo', [\App\Http\Controllers\PublicPageController::class, 'demo'])->name('demo');
});
// The user manual on the public site (resources/manual; App\Support\Manual). The PDF is
// public on the online service: a one-off buyer downloads it before installing.
Route::middleware(['edition:saas', 'landing'])->group(function () {
    Route::get('/manual', [\App\Http\Controllers\ManualController::class, 'index'])->name('manual.index');
    Route::get('/manual.pdf', [\App\Http\Controllers\ManualController::class, 'pdf'])->name('manual.pdf');
    Route::get('/manual/{slug}', [\App\Http\Controllers\ManualController::class, 'show'])->where('slug', '\d{2}-[a-z0-9-]+')->name('manual.show');
});

// Phase 2 item: the first six guides for clinic owners (config/guides.php; roadmap 02 §7, 06 §4).
Route::middleware(['edition:saas', 'landing'])->group(function () {
    Route::get('/guides', [\App\Http\Controllers\GuideController::class, 'index'])->name('guides.index');
    Route::get('/guides/{slug}', [\App\Http\Controllers\GuideController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('guides.show');
});

// A WhatsApp button on the public site, counted on its way to wa.me (GoController).
Route::get('/go/whatsapp', [\App\Http\Controllers\GoController::class, 'whatsapp'])
    ->middleware(['edition:saas', 'throttle:60,1'])->name('go.whatsapp');

// Walk into the demo as a role: a form POST, never a link, so no crawler or
// prefetch can sign anybody in; the account is the server's choice.
Route::post('/demo/{role}', [\App\Http\Controllers\PublicPageController::class, 'enterDemo'])
    ->middleware(['edition:saas', 'throttle:12,1'])->where('role', '(?!leave$)[a-z_]+')->name('demo.enter');

Route::get('/robots.txt', [\App\Http\Controllers\MarketingController::class, 'robots'])->name('robots');
// For an uptime monitor: overall status and each check's status (td:check has the details).
Route::get('/health', \App\Http\Controllers\HealthController::class)->middleware('throttle:60,1')->name('health');

Route::get('/contact', [\App\Http\Controllers\MarketingController::class, 'contact'])
    ->middleware(['edition:saas', 'landing'])->name('contact');
Route::post('/contact', [\App\Http\Controllers\MarketingController::class, 'enquire'])->middleware('edition:saas')->name('contact.send');

// Quote me in the other currency. A POST because it sets a cookie, and a
// GET that changes state is a GET a crawler will change state with.
Route::post('/currency', [\App\Http\Controllers\MarketingController::class, 'currency'])->middleware('edition:saas')->name('currency');

// The picture check on the public forms (App\Support\HumanCheck). Throttled:
// a script fetching pictures to learn from them gets very few. Both editions:
// a standalone installation's forgot-password and sign-in pages ask for it
// too — online-only, the picture never loaded and nobody could reset a password.
Route::middleware(['throttle:40,1'])->group(function () {
    Route::get('/human-check/new', [\App\Http\Controllers\HumanCheckController::class, 'fresh'])->name('human-check.new');
    Route::get('/human-check/{id}.png', [\App\Http\Controllers\HumanCheckController::class, 'image'])
        ->where('id', '[A-Za-z0-9]{32}')->name('human-check.image');
});

Route::get('/sitemap.xml', [\App\Http\Controllers\MarketingController::class, 'sitemap'])->middleware('edition:saas')->name('sitemap');
Route::get('/sitemap-pages.xml', [\App\Http\Controllers\MarketingController::class, 'sitemapPages'])->middleware('edition:saas')->name('sitemap.pages');
Route::view('/privacy', 'marketing.privacy')->middleware(['edition:saas', 'landing'])->name('privacy');
Route::view('/terms', 'marketing.terms')->middleware(['edition:saas', 'landing'])->name('terms');

// One-off product sales and a separate buyer portal; neither exists in a
// customer installation. Payment status is verified against Pesapal, not the
// browser redirect query string.
Route::middleware('edition:saas')->group(function () {
    // Renamed for the reader: "your own copy" says what it is. The old address stays as a permanent redirect.
    Route::get('/own-your-copy', [\App\Http\Controllers\StandalonePurchaseController::class, 'products'])->middleware('landing')->name('standalone.products');
    Route::redirect('/standalone', '/own-your-copy', 301);
    Route::post('/standalone/checkout/{product}', [\App\Http\Controllers\StandalonePurchaseController::class, 'checkout'])
        ->middleware('throttle:8,60')->name('standalone.checkout');
    // The buying wizard: choose, your details, review and pay, status. What the buyer typed,
    // the order being paid and the licence bought are kept in this session and in encrypted
    // cookies, so a closed tab or a failed payment page never loses them.
    Route::get('/standalone/buy/{product}', [\App\Http\Controllers\StandalonePurchaseController::class, 'details'])->whereNumber('product')->name('standalone.buy');
    Route::post('/standalone/buy/{product}', [\App\Http\Controllers\StandalonePurchaseController::class, 'saveDetails'])->whereNumber('product')->middleware('throttle:20,1')->name('standalone.buy.save');
    Route::get('/standalone/review', [\App\Http\Controllers\StandalonePurchaseController::class, 'review'])->name('standalone.review');
    Route::post('/standalone/pay', [\App\Http\Controllers\StandalonePurchaseController::class, 'pay'])->middleware('throttle:10,1')->name('standalone.pay');
    Route::get('/standalone/status', [\App\Http\Controllers\StandalonePurchaseController::class, 'status'])->name('standalone.status');
    Route::post('/standalone/status', [\App\Http\Controllers\StandalonePurchaseController::class, 'checkStatus'])->middleware('throttle:20,1')->name('standalone.status.check');
    Route::post('/my-licence/forget', [\App\Http\Controllers\StandalonePurchaseController::class, 'forget'])->name('standalone.portal.forget');
    Route::get('/standalone/payment/return', [\App\Http\Controllers\StandalonePurchaseController::class, 'callback'])
        ->name('standalone.checkout.callback');
    Route::get('/my-licence', [\App\Http\Controllers\StandalonePurchaseController::class, 'portal'])->name('standalone.portal');
    Route::post('/my-licence/link', [\App\Http\Controllers\StandalonePurchaseController::class, 'requestLink'])
        ->middleware('throttle:4,60')->name('standalone.portal.link');
    Route::get('/my-licence/claim/{licence}', [\App\Http\Controllers\StandalonePurchaseController::class, 'claim'])
        ->middleware(['signed', 'throttle:20,1'])->name('standalone.portal.claim');
    Route::get('/my-licence/licence.json', [\App\Http\Controllers\StandalonePurchaseController::class, 'downloadLicence'])->name('standalone.portal.licence');
    Route::get('/my-licence/INSTALL.md', [\App\Http\Controllers\StandalonePurchaseController::class, 'downloadGuide'])->name('standalone.portal.guide');
    Route::get('/my-licence/guide', [\App\Http\Controllers\StandalonePurchaseController::class, 'guide'])->name('standalone.portal.guide.page');
    Route::post('/my-licence/renew', [\App\Http\Controllers\StandalonePurchaseController::class, 'renew'])
        ->middleware('throttle:8,60')->name('standalone.portal.renew');
    Route::post('/my-licence/domain', [\App\Http\Controllers\StandalonePurchaseController::class, 'changeDomain'])
        ->middleware('throttle:6,60')->name('standalone.portal.domain');
    Route::get('/my-licence/receipts/{order}', \App\Http\Controllers\StandaloneReceiptController::class)->whereNumber('order')->name('standalone.portal.receipt');
    Route::get('/my-licence/releases/{release}', [\App\Http\Controllers\StandalonePurchaseController::class, 'downloadRelease'])->name('standalone.portal.release');

    // What an installed copy asks for: the newest release its licence may
    // install, and the file. The licence id is the credential (a random UUID
    // only the licence file and the portal carry); the download is signed.
    Route::get('/standalone/updates', [\App\Http\Controllers\StandaloneUpdateFeedController::class, 'latest'])
        ->middleware('throttle:30,1')->name('standalone.updates.latest');
    Route::get('/standalone/updates/{release}/download', [\App\Http\Controllers\StandaloneUpdateFeedController::class, 'download'])
        ->middleware(['signed', 'throttle:10,1'])->name('standalone.updates.download');
});

// A patient opens an invoice or receipt from a WhatsApp/SMS link, no login. Signed
// (and expiring), revocable through the invoice's share nonce, no patient detail in
// the URL (docs/api/journeys.md).
Route::middleware(['signed', 'throttle:30,1'])->prefix('s')->group(function () {
    Route::get('i/{uuid}/{nonce}', [\App\Http\Controllers\SharedDocumentController::class, 'invoice'])->name('share.invoice');
    Route::get('r/{uuid}/{nonce}', [\App\Http\Controllers\SharedDocumentController::class, 'receipt'])->name('share.receipt');
});

// ── Patient links and document verification (roadmap 05 §4) ──────────────
// A patient opens a result, summary, prescription or quote from a text, no login:
// a code or their date of birth unlocks it, 5 wrong tries lock it, every open is
// logged. /v/{code} is the page the QR on every printed document opens.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('p/{token}', [\App\Http\Controllers\PatientLinkController::class, 'show'])->name('patient-link.show');
    Route::post('p/{token}', [\App\Http\Controllers\PatientLinkController::class, 'unlock'])->middleware('throttle:10,1')->name('patient-link.unlock');
    Route::get('p/{token}/document', [\App\Http\Controllers\PatientLinkController::class, 'document'])->name('patient-link.document');
    Route::get('v', [\App\Http\Controllers\DocumentVerificationController::class, 'form'])->name('verify.form');
    Route::get('v/{code}', [\App\Http\Controllers\DocumentVerificationController::class, 'show'])->name('verify.show');
});

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
    // Recorded: it is where the "See a Live Demo" sitelink lands, and a 404
    // here on a live site is exactly what the traffic screen should show.
    Route::get('/test-login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'createTest'])
        ->middleware(['edition:saas', 'landing'])->name('test-login');

    // Public hospital self-registration (starts a trial)
    Route::get('/register', [\App\Http\Controllers\Auth\RegistrationController::class, 'show'])
        ->middleware(['edition:saas', 'landing'])->name('register');
});

Route::post('/admin/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store']);
Route::post('/admin/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy'])->name('admin.logout');

// Leave the demonstration and go and create a real hospital. A fixed
// destination rather than a redirect parameter on logout — see the controller.
Route::post('/demo/leave', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'leaveDemo'])
    ->middleware(['edition:saas', 'auth'])
    ->name('demo.leave');
Route::redirect('/login', '/admin/login')->name('login');
// Ten tries an hour from one connection: plenty for a person correcting a
// typo, a wall for a script creating hospitals.
Route::post('/register', [\App\Http\Controllers\Auth\RegistrationController::class, 'store'])->middleware(['edition:saas', 'throttle:10,60']);

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
    Route::get('patients/{patient}/statement', [\App\Http\Controllers\Admin\PatientController::class, 'statement'])->name('patients.statement');
    Route::get('patients/{patient}/id-card', [\App\Http\Controllers\Admin\PatientController::class, 'idCard'])->name('patients.id-card');
    Route::get('patients/{patient}', \App\Livewire\Patients\Show::class)->name('patients.show');
    Route::get('patients/{patient}/dental-quote', \App\Http\Controllers\Admin\DentalQuoteController::class)->name('patients.dental-quote');
    // Item A, the dental case sheet: the patient's instructions after treatment (PDF).
    Route::get('visits/{visit}/dental-instructions', \App\Http\Controllers\Admin\DentalInstructionsController::class)->name('visits.dental-instructions');

    // ── Patient links (roadmap 05 §4) ──────────────────────────
    Route::get('patient-links', \App\Livewire\PatientLinks\Index::class)->name('patient-links.index');
    Route::get('visits/{visit}/prescription', \App\Http\Controllers\Admin\PrescriptionPdfController::class)->name('visits.prescription');

    // ── Maternity and child health (docs/mother-child.md) ────
    // Pregnancy, immunisation and growth are tabs on the patient record; this is the day's due list.
    Route::get('immunisations', \App\Livewire\Immunisations\Index::class)->name('immunisations.index');

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
    // The cashier's day, to close the till against (same figures as the app's).
    Route::get('cashier/day', \App\Livewire\Billing\CashierDay::class)->name('cashier.day');
    Route::get('cashier/day/pdf', [\App\Http\Controllers\Admin\CashierDayController::class, 'pdf'])->name('cashier.day.pdf');
    Route::get('invoices', \App\Livewire\Invoices\Index::class)->name('invoices.index');
    Route::get('invoices/{invoice}', \App\Livewire\Invoices\Show::class)->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\Admin\InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('payments/{payment}/receipt', [\App\Http\Controllers\Admin\PaymentController::class, 'receipt'])->name('payments.receipt');
    // Roll paper for a receipt printer, 58 or 80 mm (?w=58).
    Route::get('payments/{payment}/receipt/thermal', [\App\Http\Controllers\Admin\ThermalController::class, 'receipt'])->name('payments.receipt.thermal');
    Route::get('visits/{visit}/ticket', [\App\Http\Controllers\Admin\ThermalController::class, 'ticket'])->name('visits.ticket');
    Route::post('invoices/{invoice}/pay/flutterwave', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'start'])->name('invoices.flutterwave');
    // ── Refunds and credit notes (roadmap 04 §8, docs/refunds.md) ──
    Route::get('credits', \App\Livewire\Credits\Index::class)->name('credits.index');
    Route::get('credits/{credit}/pdf', [\App\Http\Controllers\Admin\InvoiceCreditController::class, 'pdf'])->name('credits.pdf');

    // ── Dispensing (Phase 2 Step 12b) ─────────────────────────
    Route::get('dispensations/{dispensation}', \App\Livewire\Dispensations\Show::class)->name('dispensations.show');

    // ── Pharmacy / inventory (Phase 2 Step 12a) ───────────────
    Route::get('stock/alerts', \App\Livewire\Stock\Alerts::class)->name('stock.alerts');
    // The store's record book: everything in, everything out, and why.
    Route::get('stock/movements', \App\Livewire\Stock\Movements::class)->name('stock.movements');
    Route::get('stock', \App\Livewire\Stock\Index::class)->name('stock.index');
    Route::get('stock/{stock}', \App\Livewire\Stock\Show::class)->name('stock.show');
    Route::get('stock-categories', \App\Livewire\StockCategories\Index::class)->name('stock-categories.index');

    // ── Procurement: suppliers, purchase orders, goods received (roadmap 04 §7) ──
    Route::get('suppliers', \App\Livewire\Suppliers\Index::class)->name('suppliers.index');
    Route::get('purchase-orders', \App\Livewire\PurchaseOrders\Index::class)->name('purchase-orders.index');
    Route::get('purchase-orders/{purchaseOrder}', \App\Livewire\PurchaseOrders\Show::class)->name('purchase-orders.show');
    Route::get('purchase-orders/{purchaseOrder}/pdf', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'pdf'])->name('purchase-orders.pdf');

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

    // ── The books (docs/finance.md) ───────────────────────────
    Route::get('finance', \App\Livewire\Finance\Overview::class)->name('finance.index');
    Route::get('finance/transactions', \App\Livewire\Finance\Transactions::class)->name('finance.transactions');
    Route::get('finance/journal', \App\Livewire\Finance\Journal::class)->name('finance.journal');
    Route::get('finance/accounts', \App\Livewire\Finance\Accounts::class)->name('finance.accounts');
    Route::get('finance/accounts/{account}', \App\Livewire\Finance\AccountShow::class)->name('finance.accounts.show');
    Route::get('finance/statements', \App\Livewire\Finance\Statements::class)->name('finance.statements');
    Route::get('finance/settings', \App\Livewire\Finance\Settings::class)->name('finance.settings');
    // Taken away: a statement on the letterhead, or any of it as a spreadsheet.
    Route::get('finance/statements/pdf', [\App\Http\Controllers\Admin\FinanceExportController::class, 'pdf'])->name('finance.statement-pdf');
    Route::get('finance/export', [\App\Http\Controllers\Admin\FinanceExportController::class, 'csv'])->name('finance.export');

    // ── Notifications (Phase 4 Step 19) ───────────────────────
    Route::get('notifications', \App\Livewire\Notifications\Index::class)->name('notifications.index');
    // The user manual, in both editions (resources/manual).
    Route::get('manual/pdf', [\App\Http\Controllers\ManualController::class, 'pdf'])->name('manual.pdf');
    Route::get('manual/{slug?}', \App\Livewire\Manual\Show::class)->where('slug', '\d{2}-[a-z0-9-]+')->name('manual');
    Route::post('device-tokens', [\App\Http\Controllers\Admin\DeviceTokenController::class, 'store'])->name('device-tokens.store');

    // ── Reports & dashboards (Phase 4 Step 20) ────────────────
    Route::get('reports', \App\Livewire\Reports\Index::class)->name('reports.index');
    // The same figures as a document, over the same range — a board paper
    // rather than a screen. Opens in its own tab, like every other generated
    // document here.
    Route::get('reports/pdf', [\App\Http\Controllers\Admin\ReportController::class, 'pdf'])->name('reports.pdf');
    // The Ministry of Health forms (HMIS 105, 108, 033b) and the coding worklist they depend on.
    Route::get('reports/hmis', \App\Livewire\Reports\Hmis::class)->name('reports.hmis');
    Route::get('reports/hmis/{form}.{format}', [\App\Http\Controllers\Admin\HmisController::class, 'export'])->where(['form' => '105|108|033b', 'format' => 'pdf|csv'])->name('reports.hmis.export');
    Route::get('diagnoses', \App\Livewire\Diagnoses\Worklist::class)->name('diagnoses.worklist');

    // ── Subscription / plan checkout (Step 21) ────────────────
    Route::get('subscription', \App\Livewire\Subscription\Index::class)->middleware('edition:saas')->name('subscription.index');
    Route::post('subscription/{plan}/checkout', [\App\Http\Controllers\Admin\SubscriptionCheckoutController::class, 'checkout'])->middleware('edition:saas')->name('subscription.checkout');

    // ── Configuration ─────────────────────────────────────────
    Route::get('settings/hospital', \App\Livewire\Settings\Hospital::class)->name('settings.hospital');
    Route::get('settings/billing', \App\Livewire\Settings\Billing::class)->name('settings.billing');
    Route::get('settings/practice', \App\Livewire\Settings\Practice::class)->name('settings.practice');
    Route::get('settings/messages', \App\Livewire\Settings\Messages::class)->name('settings.messages');
    Route::get('settings', \App\Livewire\Settings\Site::class)->middleware('edition:saas')->name('settings.index');

    // ── Data protection: the Act's pack, patients' requests, the full export (docs/compliance/README.md) ──
    Route::get('settings/data-protection', \App\Livewire\Settings\DataProtection::class)->name('settings.data-protection');
    Route::get('settings/data-protection/{document}.pdf', [\App\Http\Controllers\Admin\DataProtectionController::class, 'document'])->where('document', '[a-z-]+')->name('compliance.document');
    Route::get('settings/data-export/{token}', \App\Http\Controllers\Admin\DataExportController::class)->middleware('no-store')->name('data-exports.download');
    Route::get('data-requests', \App\Livewire\DataRequests\Index::class)->name('data-requests.index');
    Route::get('patients/{patient}/personal-data.{format}', [\App\Http\Controllers\Admin\DataProtectionController::class, 'patient'])->where('format', 'pdf|json')->name('patients.personal-data');

    // A standalone owner's own server: release, licence, updates, backups.
    Route::middleware('edition:standalone')->group(function () {
        Route::get('system', \App\Livewire\System\Index::class)->name('system.index');
        // A release is larger than Livewire's temporary uploads allow.
        Route::post('system/update', [\App\Http\Controllers\Admin\SystemUpdateController::class, 'upload'])->name('system.upload');
        Route::get('system/backups/{name}', [\App\Http\Controllers\Admin\SystemUpdateController::class, 'backup'])
            ->where('name', 'true-doctor-backup-\d{8}-\d{6}\.zip')->name('system.backup');
    });

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

    // ── Audit log (roadmap 07 §3, docs/audit.md) ──────────────
    Route::get('audit-log', \App\Livewire\Audit\Index::class)->middleware('permission:manage-settings')->name('audit.index');
});

/*
|--------------------------------------------------------------------------
| Super Admin (SaaS central — HMS_PLAN.md §10 convention)
|--------------------------------------------------------------------------
*/
Route::prefix('super')->middleware(['edition:saas', 'auth', 'super', 'no-store'])->name('super.')->group(function () {
    Route::redirect('/', '/super/hospitals');

    Route::get('hospitals', \App\Livewire\Super\Hospitals\Index::class)->name('hospitals.index');
    Route::get('plans', \App\Livewire\Super\Plans\Index::class)->name('plans.index');
    Route::get('releases', \App\Livewire\Super\Releases\Index::class)->name('releases.index');
    Route::get('releases/{release}/download', \App\Http\Controllers\Super\StandaloneReleaseDownloadController::class)->name('releases.download');
    Route::get('products', \App\Livewire\Super\Products\Index::class)->name('products.index');
    Route::get('licences', \App\Livewire\Super\Licences\Index::class)->name('licences.index');
    Route::get('errors', \App\Livewire\Super\Errors\Index::class)->name('errors.index');
    Route::get('messaging', \App\Livewire\Super\Messaging\Index::class)->name('messaging.index');
    Route::get('subscriptions', \App\Livewire\Super\Subscriptions\Index::class)->name('subscriptions.index');

    // Where the people arriving at the public site came from, and which of
    // them became a hospital. Platform data, so it lives here rather than in
    // a tenant's own reports.
    Route::get('traffic', \App\Livewire\Super\Traffic\Index::class)->name('traffic.index');
});

/*
|--------------------------------------------------------------------------
| Payment gateways (public — no session; authenticity verified server-side)
|--------------------------------------------------------------------------
*/
// The SMS gateway's delivery reports and replies (STOP). A secret in the path, not a session.
Route::post('gateway/sms/delivery/{token}', [\App\Http\Controllers\SmsWebhookController::class, 'delivery'])->middleware('throttle:240,1')->name('gateway.sms.delivery');
Route::post('gateway/sms/inbound/{token}', [\App\Http\Controllers\SmsWebhookController::class, 'inbound'])->middleware('throttle:240,1')->name('gateway.sms.inbound');
Route::get('gateway/flutterwave/callback', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'callback'])->name('gateway.callback');
Route::post('gateway/flutterwave/webhook', [\App\Http\Controllers\Admin\GatewayPaymentController::class, 'webhook'])->name('gateway.webhook');

// Pesapal — subscription checkout. Both GET: the callback is a browser
// redirect, and the IPN URL is registered with ipn_notification_type "GET"
// (PesapalGateway::ipnId) so it needs no CSRF exemption.
Route::get('gateway/pesapal/callback', [\App\Http\Controllers\Admin\PesapalPaymentController::class, 'callback'])->middleware('edition:saas')->name('gateway.pesapal.callback');
Route::get('gateway/pesapal/ipn', [\App\Http\Controllers\Admin\PesapalPaymentController::class, 'ipn'])->middleware('edition:saas')->name('gateway.pesapal.ipn');

require __DIR__.'/auth.php';
