<?php

// API routes: pharmacy and catalogues. Inside the authenticated, subscribed group of routes/api.php.
// Documented in docs/api/pharmacy.md.

use App\Http\Controllers\Api\V1\LabTestController;
use App\Http\Controllers\Api\V1\RadiologyStudyController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StockAlertController;
use App\Http\Controllers\Api\V1\StockCategoryController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use Illuminate\Support\Facades\Route;

// Stock alerts and the store-wide ledger.
Route::get('stock-alerts', [StockAlertController::class, 'index'])->name('api.stock-alerts.index');
Route::post('stock-alerts/sweep', [StockAlertController::class, 'sweep'])->name('api.stock-alerts.sweep');
Route::get('stock-movements', [StockLedgerController::class, 'index'])->name('api.stock-movements.index');

// Stock items: the read side is in routes/api.php; the web form's write side is here.
Route::post('stock-items', [StockItemController::class, 'store'])->name('api.stock-items.store');
Route::match(['put', 'patch'], 'stock-items/{stock}', [StockItemController::class, 'update'])->name('api.stock-items.update');
Route::delete('stock-items/{stock}', [StockItemController::class, 'destroy'])->name('api.stock-items.destroy');

// Catalogues.
Route::apiResource('services', ServiceController::class)->parameters(['services' => 'service'])->names('api.services');
Route::apiResource('lab-tests', LabTestController::class)->parameters(['lab-tests' => 'labTest'])->names('api.lab-tests');
Route::apiResource('radiology-studies', RadiologyStudyController::class)->parameters(['radiology-studies' => 'radiologyStudy'])->names('api.radiology-studies');
Route::apiResource('stock-categories', StockCategoryController::class)->parameters(['stock-categories' => 'stockCategory'])->names('api.stock-categories');
