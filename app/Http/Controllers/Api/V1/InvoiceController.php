<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only invoices for billing/integration clients (billing.view). */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::with('patient')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($invoices, InvoiceResource::collection($invoices->items()));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(new InvoiceResource($invoice->load(['patient', 'items'])));
    }
}
