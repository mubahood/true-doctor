<?php

use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders and the bill at the counter (docs/api/orders-and-bill.md)
|--------------------------------------------------------------------------
| Loaded inside the authenticated, idempotent API group of routes/api.php.
| Each endpoint calls the service method the web's panel calls: an order's
| line corrected in place (OrderDetail::saveItem), and the bill's Add charge,
| Discount and "Check the bill adds up" (Visits\Panels\Charges).
*/

// One line of an order, corrected in place: quantity, note, a ranged service's price.
Route::patch('orders/{order}/items/{item}', [OrderController::class, 'updateItem'])->whereNumber('item')->name('api.orders.items.update');

Route::prefix('visits/{visit}/bill')->name('api.bill.')->controller(BillingController::class)->group(function () {
    // A charge at the counter: a service or a product, on an order of its own.
    Route::post('lines', 'addCharge')->name('lines.store');
    // The discount agreed at the counter: an amount or a percentage, with the reason.
    Route::put('discount', 'discount')->name('discount');
    // Every standing line back in step with its own unit price and quantity.
    Route::post('rebill', 'rebill')->name('rebill');
});
