<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The purchase order as a plain PDF, to print or send to the supplier. The
 * web page's PDF button and the app (`api.purchase-orders.pdf`) both open it.
 */
class PurchaseOrderController extends Controller
{
    public function pdf(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'items.stockItem', 'orderedBy', 'createdBy']);

        return Pdf::loadView('pdf.purchase-order', [
            'order' => $purchaseOrder,
            'hospital' => app(\App\Support\HospitalSettings::class)->hospital(),
        ])->stream("purchase-order-{$purchaseOrder->po_no}.pdf");
    }
}
