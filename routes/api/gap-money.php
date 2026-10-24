<?php

// API routes: the money desk on the phone. Refunds and credit notes (one
// document, read by the approver), and the Ministry reports (HMIS 105, 108,
// 033b). Inside the authenticated, subscribed group of routes/api.php; each
// endpoint is authorised exactly as its web page is (docs/api/refunds.md,
// docs/api/reports.md).

use App\Http\Controllers\Admin\HmisController as HmisFiles;
use App\Http\Controllers\Api\V1\HmisController;
use App\Http\Controllers\Api\V1\InvoiceCreditController;
use Illuminate\Support\Facades\Route;

// One credit note or refund with its invoice, lines and figures.
Route::get('credits/{credit}', [InvoiceCreditController::class, 'show'])->name('api.credits.show');

// The Ministry reports: which forms, one form's figures for a month or week,
// and the web's own PDF and CSV behind the same check.
Route::get('reports/hmis', [HmisController::class, 'index'])->name('api.reports.hmis.index');
Route::get('reports/hmis/{form}.{format}', [HmisFiles::class, 'export'])->where(['form' => '105|108|033b', 'format' => 'pdf|csv'])->name('api.reports.hmis.export');
Route::get('reports/hmis/{form}', [HmisController::class, 'show'])->where('form', '105|108|033b')->name('api.reports.hmis.show');
