<?php

// API routes: the journeys (Super app plan W2) - next actions, agenda, share links,
// the cashier's day, debtors, the dispense queue, the ward round and handover,
// duplicate check. Inside the authenticated, subscribed group of routes/api.php.
// Docs: docs/api/journeys.md.

use App\Http\Controllers\Api\V1\CashierController;
use App\Http\Controllers\Api\V1\DebtorController;
use App\Http\Controllers\Api\V1\DispenseQueueController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PatientHelperController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\WardRoundController;
use Illuminate\Support\Facades\Route;

Route::get('me/next-actions', [MeController::class, 'nextActions'])->name('api.me.next-actions');
Route::get('me/agenda', [MeController::class, 'agenda'])->name('api.me.agenda');

// Share an invoice or receipt by a signed link; revoke it; the receipt as data for a thermal printer.
Route::post('invoices/{invoice}/share', [ShareController::class, 'shareInvoice'])->middleware('throttle:30,1')->name('api.invoices.share');
Route::delete('invoices/{invoice}/share', [ShareController::class, 'revokeInvoice'])->name('api.invoices.share.revoke');
Route::post('payments/{payment:uuid}/share', [ShareController::class, 'sharePayment'])->middleware('throttle:30,1')->name('api.payments.share');
Route::delete('payments/{payment:uuid}/share', [ShareController::class, 'revokePayment'])->name('api.payments.share.revoke');
Route::get('payments/{payment:uuid}/receipt.json', [ShareController::class, 'receipt'])->name('api.payments.receipt.json');

Route::get('cashier/day-summary', [CashierController::class, 'daySummary'])->name('api.cashier.day-summary');
Route::get('cashier/day-summary/pdf', [CashierController::class, 'daySummaryPdf'])->name('api.cashier.day-summary.pdf');
Route::get('debtors', DebtorController::class)->name('api.debtors');

Route::get('prescriptions', [DispenseQueueController::class, 'index'])->name('api.prescriptions.queue');
Route::get('patients/{patient}/last-prescription', [DispenseQueueController::class, 'last'])->name('api.patients.last-prescription');

Route::get('ward/doses-due', [WardRoundController::class, 'dosesDue'])->name('api.ward.doses-due');
Route::get('ward/handover', [WardRoundController::class, 'handoverSeed'])->name('api.ward.handover');
Route::post('ward/handover', [WardRoundController::class, 'store'])->name('api.ward.handover.store');
Route::get('ward/handover/history', [WardRoundController::class, 'history'])->name('api.ward.handover.history');
Route::post('ward/handover/{handover:uuid}/acknowledge', [WardRoundController::class, 'acknowledge'])->name('api.ward.handover.acknowledge');
Route::get('ward/handover/{handover:uuid}/pdf', [WardRoundController::class, 'pdf'])->name('api.ward.handover.pdf');

// `patients/{patient}` (registered earlier in routes/api.php) would take "possible-duplicates"
// for a patient's uuid and answer 404. A patient's key is always a uuid, so say so on that
// route and let this literal path fall through to the route below.
foreach (Route::getRoutes()->getRoutes() as $registered) {
    if ($registered->uri() === 'api/v1/patients/{patient}' && in_array('GET', $registered->methods(), true)) {
        $registered->whereUuid('patient');
    }
}
Route::get('patients/possible-duplicates', [PatientHelperController::class, 'possibleDuplicates'])->name('api.patients.possible-duplicates');
