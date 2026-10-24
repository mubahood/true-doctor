<?php

use App\Http\Controllers\Admin\PrescriptionPdfController;
use App\Http\Controllers\Admin\VisitController as AdminVisitController;
use App\Http\Controllers\Api\V1\VisitPageController;
use Illuminate\Support\Facades\Route;

/*
| The visit page on the phone (docs/api/visit-page.md). Loaded inside the
| authenticated group of routes/api.php, so every route here has auth,
| tenancy, idempotency and the ETag cover of its neighbours.
*/

// The visit report and the prescription: the web's own controllers, so the
// phone prints the same documents behind the same policies.
Route::get('visits/{visit}/report', [AdminVisitController::class, 'report'])->name('api.visits.report');
Route::get('visits/{visit}/prescription', PrescriptionPdfController::class)->name('api.visits.prescription');

// The free times for the review booked from a visit (Book a review, the case sheet's Follow-up).
Route::get('visits/{visit}/review/times', [VisitPageController::class, 'reviewTimes'])->name('api.visits.review.times');

// The medicines a dosage is written from (the Dosage panel's picker).
Route::get('dosage/medicines', [VisitPageController::class, 'medicines'])->name('api.dosage.medicines');
