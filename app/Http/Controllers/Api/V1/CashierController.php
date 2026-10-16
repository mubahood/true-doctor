<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\CashierDaySummary;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The cashier's day: figures and the printable close-of-day sheet. Permission: billing.view (the invoice and receipt ability). */
class CashierController extends Controller
{
    public function __construct(private readonly CashierDaySummary $summary) {}

    public function daySummary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->figures($request));
    }

    public function daySummaryPdf(Request $request)
    {
        $summary = $this->figures($request);
        $hospital = app(HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.cashier-day', ['summary' => $summary, 'hospital' => $hospital])
            ->stream("cashier-{$summary['date']}.pdf");
    }

    /** @return array<string,mixed> */
    private function figures(Request $request): array
    {
        $this->authorize('viewAny', Invoice::class);

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $hospital = $request->user()->hospital;

        return $this->summary->forDate($hospital, $data['date'] ?? $this->summary->today($hospital));
    }
}
