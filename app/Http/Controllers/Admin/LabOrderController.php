<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Lab orders. Ordering (+billing) is raised from the visit workspace
 * (App\Livewire\Visits\Panels\LabOrders) and the status machine plus
 * inline results live on App\Livewire\LabOrders\Show. What is left here is the
 * one endpoint that has to leave the SPA: the DomPDF result sheet.
 */
class LabOrderController extends Controller
{
    public function pdf(LabOrder $labOrder)
    {
        abort_unless(request()->user()->can('lab.view'), 403);

        $labOrder->load(['items', 'patient', 'orderedBy']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.lab-result', ['order' => $labOrder, 'hospital' => $hospital])
            ->stream("lab-report-{$labOrder->uuid}.pdf");
    }
}
