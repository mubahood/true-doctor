<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only invoices for billing/integration clients (billing.view). */
class InvoiceController extends Controller
{
    use PagesAndDeltas;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = $this->applyDelta(Invoice::with('patient')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->matching((string) $request->query('q', ''))
            ->latest(), $request)
            ->paginate($this->perPage($request));

        return $this->deltaPage($request, $invoices, InvoiceResource::class);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(new InvoiceResource($invoice->load(['patient', 'items', 'payments.receivedBy'])));
    }
}
