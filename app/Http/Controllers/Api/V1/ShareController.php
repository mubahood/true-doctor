<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceShareService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Share an invoice or receipt as a signed link, revoke it, and the receipt as
 * data for a thermal printer. Same ability as printing the PDF (`view` on the
 * invoice = billing.view).
 */
class ShareController extends Controller
{
    public function __construct(private readonly InvoiceShareService $shares) {}

    public function shareInvoice(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        abort_if($invoice->status === \App\Enums\InvoiceStatus::Void, 404);

        return ApiResponse::success($this->shares->shareInvoice($invoice), 'Link ready.');
    }

    public function revokeInvoice(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $this->shares->revoke($invoice);

        return ApiResponse::success(['revoked' => true], 'Links for this invoice no longer work.');
    }

    public function sharePayment(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment->invoice);

        return ApiResponse::success($this->shares->sharePayment($payment), 'Link ready.');
    }

    public function revokePayment(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment->invoice);
        $this->shares->revoke($payment->invoice);

        return ApiResponse::success(['revoked' => true], 'Links for this invoice and its receipts no longer work.');
    }

    public function receipt(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment->invoice);

        return ApiResponse::success($this->shares->receiptJson($payment));
    }
}
