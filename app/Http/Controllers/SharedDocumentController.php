<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\HospitalScope;
use App\Services\InvoiceShareService;
use App\Support\CurrentHospital;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * The invoice or receipt PDF behind a signed share link (InvoiceShareService).
 *
 * No login: the signature (and its expiry) proves we issued the link, and the
 * invoice's share nonce proves it has not been revoked since. The tenant is
 * taken from the document itself so the letterhead is the right hospital's.
 */
class SharedDocumentController extends Controller
{
    public function invoice(string $uuid, string $nonce, InvoiceShareService $shares)
    {
        $invoice = Invoice::withoutGlobalScope(HospitalScope::class)->where('uuid', $uuid)->first();
        $this->guard($invoice, $nonce, $shares);

        app(CurrentHospital::class)->set($invoice->hospital_id);
        $invoice->load(['patient', 'items']);

        return $this->inline(Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'hospital' => app(\App\Support\HospitalSettings::class)->hospital()]), "{$invoice->invoice_no}.pdf");
    }

    public function receipt(string $uuid, string $nonce, InvoiceShareService $shares)
    {
        $payment = Payment::withoutGlobalScope(HospitalScope::class)->where('uuid', $uuid)->first();
        $invoice = $payment === null ? null : Invoice::withoutGlobalScope(HospitalScope::class)->find($payment->invoice_id);
        $this->guard($invoice, $nonce, $shares);

        app(CurrentHospital::class)->set($invoice->hospital_id);
        $payment->load(['invoice.patient', 'receivedBy']);

        return $this->inline(Pdf::loadView('pdf.receipt', ['payment' => $payment, 'hospital' => app(\App\Support\HospitalSettings::class)->hospital()]), "receipt-{$invoice->invoice_no}.pdf");
    }

    private function guard(?Invoice $invoice, string $nonce, InvoiceShareService $shares): void
    {
        abort_if($invoice === null || $invoice->status === \App\Enums\InvoiceStatus::Void, 404);
        abort_unless($shares->accepts($invoice, $nonce), 410, 'This link has been withdrawn.');
    }

    private function inline(\Barryvdh\DomPDF\PDF $pdf, string $name): Response
    {
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
