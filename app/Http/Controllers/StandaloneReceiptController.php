<?php

namespace App\Http\Controllers;

use App\Models\StandaloneOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A one-off copy's receipt, on the buyer's downloads page, for the licence signed in there only. */
class StandaloneReceiptController extends Controller
{
    public function __invoke(Request $request, StandaloneOrder $order): Response
    {
        $licence = StandalonePurchaseController::licenceInSession($request);
        abort_if($licence === null || $order->licence_id !== $licence->id || $order->status !== 'paid', 404);

        return Pdf::loadView('pdf.standalone-receipt', ['order' => $order, 'licence' => $licence, 'product' => $order->product])
            ->setPaper('a5')
            ->stream('True-Doctor receipt '.$order->reference.'.pdf')
            ->header('Cache-Control', 'no-store, private');
    }
}
