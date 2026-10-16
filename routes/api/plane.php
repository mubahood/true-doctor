<?php

use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Support\Facades\Route;

// API routes: the data plane - bootstrap, search, capabilities (Super app plan W1-C).
// Inside the authenticated, subscribed group of routes/api.php, which also puts
// `api.conditional` (ETag / 304) on every GET. Docs: docs/api/plane.md.

// Everything an app needs to start, in one request: meta, capabilities, the
// reference catalogues the user may see, and what has been removed.
Route::get('bootstrap', BootstrapController::class)->name('api.bootstrap');

// One search box over patients, visits, invoices, stock and staff.
Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('api.search');
