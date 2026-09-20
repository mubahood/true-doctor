<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RadiologyOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Radiology orders. Ordering (+billing) is raised from the visit workspace
 * (App\Livewire\Visits\Panels\RadiologyOrders) and the status machine
 * plus the narrative report live on App\Livewire\RadiologyOrders\Show. What is
 * left here is the one endpoint that has to leave the SPA: the DomPDF report.
 */
class RadiologyOrderController extends Controller
{
    public function pdf(RadiologyOrder $radiologyOrder)
    {
        abort_unless(request()->user()->can('radiology.view'), 403);

        $radiologyOrder->load(['items', 'patient', 'orderedBy']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.radiology-report', ['order' => $radiologyOrder, 'hospital' => $hospital])
            ->stream("radiology-report-{$radiologyOrder->uuid}.pdf");
    }
}
