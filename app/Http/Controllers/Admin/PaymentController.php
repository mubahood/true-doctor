<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Payment receipts (a DomPDF download). Recording a payment now happens in
 * App\Livewire\Invoices\Show, which calls BillingService::recordPayment
 * directly — the POST endpoint is gone (plan Part III).
 */
class PaymentController extends Controller
{
    public function receipt(Payment $payment)
    {
        $this->authorize('view', $payment->invoice);

        $payment->load(['invoice.patient', 'receivedBy']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'hospital' => $hospital])
            ->stream("receipt-{$payment->invoice->invoice_no}.pdf");
    }
}
