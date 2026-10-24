<?php

// API routes: care gaps the app fills (recalls, diagnoses to code, the lab and
// radiology documents). Inside the authenticated, subscribed group of
// routes/api.php. Contracts: docs/api/diagnoses.md, docs/api/lab-radiology.md.

use App\Http\Controllers\Api\V1\DiagnosisController;
use Illuminate\Support\Facades\Route;

// Diagnoses to code (admin.diagnoses.worklist): the list, and coding one from it.
Route::get('diagnoses/worklist', [DiagnosisController::class, 'worklist'])->name('api.diagnoses.worklist');
Route::post('diagnoses/worklist/{visit}', [DiagnosisController::class, 'code'])->name('api.diagnoses.worklist.code');

// The same report PDFs the web prints, behind the same permissions.
Route::get('lab-orders/{labOrder}/pdf', [\App\Http\Controllers\Admin\LabOrderController::class, 'pdf'])->name('api.lab-orders.pdf');
Route::get('radiology-orders/{radiologyOrder}/pdf', [\App\Http\Controllers\Admin\RadiologyOrderController::class, 'pdf'])->name('api.radiology-orders.pdf');
