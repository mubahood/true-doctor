<?php

// API routes: what the phone's procurement screens needed beyond the routes in
// routes/api.php (suppliers, purchase orders, goods received, returns).
// Inside the authenticated, subscribed group of routes/api.php.
// Docs: docs/api/procurement.md.

use App\Http\Controllers\Api\V1\ProcurementController;
use Illuminate\Support\Facades\Route;

// The web directory's Delete: archived, refused while the supplier has orders.
Route::delete('suppliers/{supplier}', [ProcurementController::class, 'supplierDestroy'])->name('api.suppliers.destroy');
