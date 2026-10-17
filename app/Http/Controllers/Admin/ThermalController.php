<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Visit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Roll-paper documents for a receipt printer (roadmap 06 §6): the receipt
 * and the queue ticket, 58 or 80 mm wide (`?w=58`). The page is as long as
 * what is on it, estimated from its lines, so the printer does not feed a
 * metre of blank paper after it.
 */
class ThermalController extends Controller
{
    /** Paper widths in PDF points (1 mm = 2.8346 pt). */
    private const WIDTHS = [58 => 164.41, 80 => 226.77];

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorize('view', $payment->invoice);
        $payment->load(['invoice.patient', 'invoice.items', 'receivedBy']);
        $width = $this->width($request);

        // Header, receipt line, patient, total, payment, footer: about 13 lines,
        // plus one per bill line (two on 58 mm, where a long name wraps).
        $lines = 13 + (int) $payment->invoice?->items->count() * ($width === 58 ? 2 : 1) + ($payment->reference ? 1 : 0);

        return $this->pdf('pdf.thermal.receipt', ['payment' => $payment, 'width' => $width], $width, $lines)
            ->stream("receipt-{$payment->invoice?->invoice_no}-{$width}mm.pdf");
    }

    public function ticket(Request $request, Visit $visit)
    {
        $this->authorize('view', $visit);
        $visit->load(['patient', 'department', 'doctor']);
        $width = $this->width($request);

        // The day's running number from the visit number (V-20261017-014 → 014).
        $number = preg_match('/-(\d+)$/', (string) $visit->visit_no, $m) === 1 ? $m[1] : (string) $visit->id;

        return $this->pdf('pdf.thermal.ticket', ['visit' => $visit, 'width' => $width, 'number' => $number], $width, 14)
            ->stream("ticket-{$visit->visit_no}-{$width}mm.pdf");
    }

    private function width(Request $request): int
    {
        return (int) $request->query('w', '80') === 58 ? 58 : 80;
    }

    /** @param  array<string,mixed>  $data */
    private function pdf(string $view, array $data, int $width, int $lines)
    {
        $lineHeight = $width === 58 ? 11 : 12.5;

        return Pdf::loadView($view, $data)->setPaper([0, 0, self::WIDTHS[$width], 40 + $lines * $lineHeight]);
    }
}
