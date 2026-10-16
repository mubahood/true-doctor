<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\CardHolderRelationship;
use App\Enums\CardHolderStatus;
use App\Enums\CardStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CardHolderApiRequest;
use App\Http\Requests\Api\CardIssueApiRequest;
use App\Http\Requests\Api\CardTermsRequest;
use App\Http\Requests\Api\CardTransactionApiRequest;
use App\Http\Requests\CardTransactionRequest;
use App\Http\Resources\CardHolderResource;
use App\Http\Resources\CardRecordResource;
use App\Http\Resources\PatientCardResource;
use App\Models\CardHolder;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Services\CardService;
use App\Services\InsuranceLedgerService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Prepaid and insurance cards (web: admin.cards.*, and the Cards panel on a
 * patient). One permission gates the subject, patients.card.manage, through
 * PatientCardPolicy; every movement of money goes through CardService.
 * The card number never leaves the server - cards are shown masked.
 */
class CardController extends Controller
{
    use AnswersFinanceRequests;

    public function __construct(private readonly CardService $cards) {}

    /**
     * patients.card.manage. Filters: status, insurer ('insured'|'own'|insurer id), owing=1,
     * q (member no, patient name/number); sort=balance|created_at|expiry & dir. Meta carries
     * the filtered list's totals {cards, held, owed}, as the web header does.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PatientCard::class);

        $filters = [
            'status' => (string) $request->query('status', ''),
            'insurer' => (string) $request->query('insurer', ''),
            'owing' => $request->boolean('owing'),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];

        $query = $this->cards->search($filters);
        $totals = $this->cards->totals($this->cards->search($filters));

        $sort = (string) $request->query('sort', '');
        if (in_array($sort, ['balance', 'created_at', 'expiry'], true)) {
            $query->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest('id');
        }

        $rows = $query->with(['patient', 'provider'])
            ->withCount(['holders' => fn ($q) => $q->where('status', CardHolderStatus::Active)])
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, PatientCardResource::collection($rows->getCollection())->resolve(), ['totals' => $totals]);
    }

    /** patients.card.manage. The statuses, insurers and relationships the card forms offer. */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', PatientCard::class);

        return ApiResponse::success([
            'statuses' => CardStatus::options(),
            'relationships' => CardHolderRelationship::options(),
            'top_up_methods' => collect(PaymentMethod::forTopUp())->map(fn ($m) => ['value' => $m, 'label' => PaymentMethod::from($m)->label()])->values(),
            'insurers' => InsuranceProvider::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_credit_limit'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'default_credit_limit' => (string) $p->default_credit_limit])->values(),
        ]);
    }

    /**
     * patients.card.manage. Find a card by its full number (sent in the body, never in a URL).
     * Returns the card masked, or 404. Throttled: a number is a secret.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PatientCard::class);
        $data = $request->validate(['number' => ['required', 'string', 'min:8', 'max:32']]);

        $card = $this->cards->findByNumber($data['number']);
        abort_if($card === null, 404);

        return ApiResponse::success($this->detail($card));
    }

    /** patients.card.manage. The card, who else may spend on it, and its balance proved against its ledger. */
    public function show(PatientCard $card): JsonResponse
    {
        $this->authorize('view', $card);

        return ApiResponse::success($this->detail($card));
    }

    /** patients.card.manage. The card's own ledger, newest first, 20 a page. */
    public function records(PatientCard $card): JsonResponse
    {
        $this->authorize('view', $card);

        $rows = CardRecord::with(['patient', 'createdBy'])->where('patient_card_id', $card->id)
            ->latest('created_at')->latest('id')->paginate(20);

        return ApiResponse::paginated($rows, CardRecordResource::collection($rows->getCollection())->resolve());
    }

    /** patients.card.manage. A patient's cards: their own and those they are a holder on. */
    public function forPatient(Patient $patient): JsonResponse
    {
        $this->authorize('manageCards', $patient);

        $cards = PatientCard::spendableBy($patient->id)->with(['patient', 'provider'])->orderByDesc('id')->get();

        return ApiResponse::success(PatientCardResource::collection($cards)->resolve());
    }

    /** patients.card.manage. {expiry?, accepts_credit?, max_credit?, insurance_provider_id?, member_no?} */
    public function issue(CardIssueApiRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('manageCards', $patient);

        $data = $request->validated();
        $data['accepts_credit'] = $request->boolean('accepts_credit');
        $data['max_credit'] = $data['accepts_credit'] ? ($data['max_credit'] ?? 0) : 0;
        $data['expiry'] = $data['expiry'] ?? null;

        try {
            $card = $this->cards->issue($patient, $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'insurance_provider_id');
        }

        return ApiResponse::success((new PatientCardResource($card->load(['patient', 'provider'])))->resolve(), "Card issued — {$card->masked()}.", 201);
    }

    /**
     * patients.card.manage. Top up or charge: {type: credit|debit, amount, description?, paid_by?: cash|mobile_money|bank
     * (credit only, default cash - which book the money lands in)}.
     */
    public function transact(CardTransactionApiRequest $request, PatientCard $card): JsonResponse
    {
        $this->authorize('update', $card);

        $data = $request->validated();
        $amount = CardTransactionRequest::normalise($data['amount']);
        $note = ($data['description'] ?? null) ?: null;

        try {
            $record = $data['type'] === 'debit'
                ? $this->cards->debit($card, $amount, $note, $request->user()->id)
                : $this->cards->credit($card, $amount, $note, $request->user()->id, ['method' => PaymentMethod::from($data['paid_by'] ?? 'cash')]);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'amount');
        }

        return ApiResponse::success(
            ['record' => (new CardRecordResource($record))->resolve(), 'card' => $this->detail($card->refresh())],
            $data['type'] === 'debit' ? 'Card charged.' : 'Card topped up.',
            201,
        );
    }

    /** patients.card.manage. {status, accepts_credit?, max_credit?, expiry?, insurance_provider_id?, member_no?} */
    public function terms(CardTermsRequest $request, PatientCard $card): JsonResponse
    {
        $this->authorize('update', $card);

        $d = $request->validated();

        try {
            $this->cards->updateTerms($card, [
                'status' => $d['status'],
                'accepts_credit' => $request->boolean('accepts_credit'),
                'max_credit' => $d['max_credit'] ?? 0,
                'expiry' => $d['expiry'] ?? null,
                'insurance_provider_id' => $d['insurance_provider_id'] ?? null,
                'member_no' => $d['member_no'] ?? null,
            ], $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'max_credit');
        }

        return ApiResponse::success($this->detail($card->refresh()), 'Card updated.');
    }

    /** patients.card.manage. {patient_uuid, relationship}: someone else who may spend on it. */
    public function addHolder(CardHolderApiRequest $request, PatientCard $card): JsonResponse
    {
        $this->authorize('update', $card);

        $d = $request->validated();
        $patient = Patient::where('uuid', $d['patient_uuid'])->firstOrFail();

        try {
            $holder = $this->cards->addHolder($card, $patient, CardHolderRelationship::from($d['relationship']), $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'patient_uuid');
        }

        return ApiResponse::success((new CardHolderResource($holder->load('patient')))->resolve(), $patient->fullName().' can now use this card.', 201);
    }

    /** patients.card.manage. Take a holder off (their past charges stay on the ledger). */
    public function revokeHolder(Request $request, PatientCard $card, int $holder): JsonResponse
    {
        $this->authorize('update', $card);

        $model = CardHolder::where('patient_card_id', $card->id)->whereKey($holder)->firstOrFail();
        $this->cards->revokeHolder($model, $request->user()->id);

        return ApiResponse::success((new CardHolderResource($model->refresh()->load('patient')))->resolve(), 'Taken off the card.');
    }

    /** patients.card.manage AND insurance.manage. The insurer's float clears this card's debt. */
    public function settle(Request $request, PatientCard $card, InsuranceLedgerService $ledger): JsonResponse
    {
        $this->authorize('update', $card);

        if ($request->user()->can('insurance.manage') !== true) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::Forbidden, 'Only someone who manages insurance can spend an insurer’s float.', 403);
        }

        try {
            $entry = $ledger->settleCard($card, null, $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success(
            ['settled' => $entry === null ? '0.00' : (string) $entry->amount, 'card' => $this->detail($card->refresh())],
            $entry === null ? 'Nothing to clear — the float is empty or the card is square.' : HospitalSettings::money((string) $entry->amount).' cleared from the insurer’s float.',
        );
    }

    /** @return array<string,mixed> */
    private function detail(PatientCard $card): array
    {
        $card->load(['patient', 'provider', 'issuer']);

        return (new PatientCardResource($card))->resolve() + [
            'holders' => CardHolderResource::collection(CardHolder::with(['patient', 'addedBy'])->where('patient_card_id', $card->id)->orderBy('status')->orderBy('id')->get())->resolve(),
            'proof' => $this->cards->reconcile($card),
        ];
    }
}
