<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\CardRecordResource;
use App\Models\CardRecord;
use App\Models\PatientCard;
use App\Services\CardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every movement on every card, newest first (web: admin.card-records.index).
 * Read-only: a ledger line has no edit and no delete. Whoever may read the
 * card ledger is whoever the web lets in (PatientCardPolicy@viewAny).
 */
class CardRecordController extends Controller
{
    use AnswersFinanceRequests;

    /** patients.card.manage. Filters: type (credit|debit), insurer ('insured'|id), from, to, q. Meta: totals {in,out,net} of the filtered ledger. */
    public function index(Request $request, CardService $cards): JsonResponse
    {
        $this->authorize('viewAny', PatientCard::class);

        $filters = [
            'type' => (string) $request->query('type', ''),
            'insurer' => (string) $request->query('insurer', ''),
            'from' => (string) $this->date($request->query('from')),
            'to' => (string) $this->date($request->query('to')),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
        if (! in_array($filters['type'], ['', 'credit', 'debit'], true)) {
            $filters['type'] = '';
        }

        $rows = $cards->ledger($filters)
            ->with(['patient', 'card.patient', 'card.provider', 'createdBy'])
            ->latest('created_at')->latest('id')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, CardRecordResource::collection($rows->getCollection())->resolve(), [
            'totals' => $cards->ledgerTotals($cards->ledger($filters)),
        ]);
    }

    /** patients.card.manage. One line with its card, patient and the insurer settlement behind it. */
    public function show(CardRecord $cardRecord): JsonResponse
    {
        $this->authorize('viewAny', PatientCard::class);

        return ApiResponse::success((new CardRecordResource($cardRecord->load(['card.patient', 'card.provider', 'patient', 'createdBy', 'settlement'])))->resolve());
    }
}
