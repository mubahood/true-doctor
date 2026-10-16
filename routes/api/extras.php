<?php

// API routes: cross-cutting extras for the app - suggestions and starter data.
// Inside the authenticated, subscribed group of routes/api.php. The public
// (unauthenticated) self-registration routes are in routes/api.php itself.
// See docs/api/extras.md.

use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\StarterDataController;
use Illuminate\Support\Facades\Route;

Route::get('insights', InsightController::class)->name('api.insights');

Route::get('admin/starter-data', [StarterDataController::class, 'index'])->name('api.admin.starter-data.index');
Route::post('admin/starter-data/{kind}', [StarterDataController::class, 'import'])->name('api.admin.starter-data.import');
