<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Invoices. Generation, payments and all money movement live in BillingService;
 * the invoice is raised from the visit workspace
 * (App\Livewire\Visits\Panels\Charges) and read on
 * App\Livewire\Invoices\Show. What is left here is the one endpoint that has to
 * leave the SPA: the DomPDF download.
 */
class InvoiceController extends Controller
{
    public function pdf(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load(['patient', 'items']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'hospital' => $hospital])
            ->stream("{$invoice->invoice_no}.pdf");
    }
}
