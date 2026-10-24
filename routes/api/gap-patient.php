<?php

// API routes: the patient record on the phone (docs/api/patient-records.md).
// Inside the authenticated, subscribed group of routes/api.php, so every write
// here is covered by the `idempotent` middleware.
//
// Each endpoint calls the service the web record's panel calls, behind the
// same policy: documents (DocumentService, PatientPolicy@manageDocuments),
// insurance cover (PatientService, InsuranceClaimPolicy), dependents
// (PatientService, PatientPolicy@manageDependents) and treatment records
// (TreatmentService, TreatmentRecordPolicy). Files and the ID card stream
// through the web's own controllers, so the phone gets the same bytes.

use App\Http\Controllers\Admin\PatientController as AdminPatientController;
use App\Http\Controllers\Admin\PatientDocumentController as AdminPatientDocumentController;
use App\Http\Controllers\Admin\TreatmentRecordController as AdminTreatmentRecordController;
use App\Http\Controllers\Api\V1\PatientCoverageController;
use App\Http\Controllers\Api\V1\PatientDependentController;
use App\Http\Controllers\Api\V1\PatientDocumentController;
use App\Http\Controllers\Api\V1\PatientLinkController;
use App\Http\Controllers\Api\V1\TreatmentRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('patients/{patient}')->whereUuid('patient')->group(function () {
    // Documents: the private disk, streamed behind the policy.
    Route::get('documents', [PatientDocumentController::class, 'index'])->name('api.patients.documents.index');
    Route::post('documents', [PatientDocumentController::class, 'store'])->name('api.patients.documents.store');
    Route::get('documents/{document}/download', [AdminPatientDocumentController::class, 'download'])->whereUuid('document')->name('api.patients.documents.download');
    Route::delete('documents/{document}', [PatientDocumentController::class, 'destroy'])->whereUuid('document')->name('api.patients.documents.destroy');

    // Insurance cover: which insurer pays what share, and until when.
    Route::get('insurances', [PatientCoverageController::class, 'index'])->name('api.patients.insurances.index');
    Route::post('insurances', [PatientCoverageController::class, 'store'])->name('api.patients.insurances.store');
    Route::delete('insurances/{insurance}', [PatientCoverageController::class, 'destroy'])->whereNumber('insurance')->name('api.patients.insurances.destroy');

    // Dependents and guardians.
    Route::get('dependents', [PatientDependentController::class, 'index'])->name('api.patients.dependents.index');
    Route::post('dependents', [PatientDependentController::class, 'store'])->name('api.patients.dependents.store');
    Route::delete('dependents/{link}', [PatientDependentController::class, 'destroy'])->whereNumber('link')->name('api.patients.dependents.destroy');

    // Treatment records with their photos.
    Route::get('treatments', [TreatmentRecordController::class, 'index'])->name('api.patients.treatments.index');
    Route::post('treatments', [TreatmentRecordController::class, 'store'])->name('api.patients.treatments.store');
    Route::delete('treatments/{treatment}', [TreatmentRecordController::class, 'destroy'])->whereUuid('treatment')->name('api.patients.treatments.destroy');
    Route::get('treatment-photos/{item}', [AdminTreatmentRecordController::class, 'photo'])->whereNumber('item')->name('api.patients.treatments.photo');

    // The ID card the web prints (A7, with the QR of the patient number).
    Route::get('id-card', [AdminPatientController::class, 'idCard'])->name('api.patients.id-card');

    // What can be sent to this patient by link right now (docs/api/patient-links.md).
    Route::get('shareable', [PatientLinkController::class, 'shareable'])->name('api.patients.shareable');
});
