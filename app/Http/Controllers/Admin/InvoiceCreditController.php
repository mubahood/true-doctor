<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CreditStatus;
use App\Http\Controllers\Controller;
use App\Models\InvoiceCredit;
use Barryvdh\DomPDF\Facade\Pdf;

/** A credit note or refund as a plain PDF, like the receipt (docs/refunds.md). Web and API. */
class InvoiceCreditController extends Controller
{
    public function pdf(InvoiceCredit $credit)
    {
        $this->authorize('view', $credit);
        // Only a decided document is a document; a request is not printed.
        abort_unless($credit->status === CreditStatus::Approved, 404);

        $credit->load(['invoice.patient', 'invoice.visit', 'requestedBy', 'decidedBy', 'card']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.credit-note', ['credit' => $credit, 'hospital' => $hospital])
            ->stream("{$credit->number}.pdf");
    }
}
