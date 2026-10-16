<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\DebtorsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GET /debtors - invoices with money still owed, aged (docs/api/journeys.md). Permission: billing.view, as the invoice list. */
class DebtorController extends Controller
{
    use PagesAndDeltas;

    public function __invoke(Request $request, DebtorsService $debtors): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        // An unescaped "+" in a query string arrives as a space, which the
        // TrimStrings middleware then removes: "90+" reaches us as "90".
        if ($request->query('bucket') !== null) {
            $bucket = trim(str_replace(' ', '+', (string) $request->query('bucket')));
            $request->query->set('bucket', $bucket === '90' ? '90+' : $bucket);
        }

        $data = $request->validate([
            'bucket' => ['nullable', 'string', Rule::in(DebtorsService::BUCKETS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $debtors->page($data['bucket'] ?? null, $data['q'] ?? null, $this->perPage($request));

        $rows = collect($page->items())->map(function (Invoice $i) use ($debtors) {
            $age = $debtors->ageDays($i);

            return [
                'uuid' => $i->uuid,
                'number' => $i->invoice_no,
                'invoice_no' => $i->invoice_no,
                'patient' => [
                    'uuid' => $i->patient?->uuid,
                    'name' => $i->patient?->full_name,
                    'patient_no' => $i->patient?->patient_no,
                    'phone' => $i->patient?->phone_1,
                ],
                'currency' => $i->currency,
                'total' => (string) $i->total,
                'balance' => (string) $i->balance,
                'age_days' => $age,
                'bucket' => DebtorsService::bucketOf($age),
                'issued_at' => $i->created_at?->toIso8601String(),
            ];
        })->all();

        $aging = $debtors->aging();

        return ApiResponse::paginated($page, $rows, [
            'aging' => $aging['aging'],
            'total_outstanding' => $aging['total_outstanding'],
            'outstanding_count' => $aging['count'],
        ]);
    }
}
