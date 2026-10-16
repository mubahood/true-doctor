<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FloatAdjustRequest;
use App\Http\Requests\Api\FloatDepositRequest;
use App\Http\Requests\Api\InsuranceProviderApiRequest;
use App\Http\Resources\InsuranceProviderResource;
use App\Http\Resources\InsuranceTransactionResource;
use App\Http\Resources\PatientCardResource;
use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\PatientCard;
use App\Services\InsuranceLedgerService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Insurers and their floats (web: admin.insurance-providers.*), by
 * InsuranceProviderPolicy: read = insurance.view, change = insurance.manage.
 * Money moves only through InsuranceLedgerService.
 */
class InsuranceProviderController extends Controller
{
    use AnswersFinanceRequests;

    public function __construct(private readonly InsuranceLedgerService $ledger) {}

    /** insurance.view. ?q= on name or code. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $rows = InsuranceProvider::query()
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, InsuranceProviderResource::collection($rows->getCollection())->resolve());
    }

    /** insurance.manage. {name (unique), code?, contact_person?, contact_phone?, contact_email?, is_active?}. An archived insurer of the same name is restored, as on the web. */
    public function store(InsuranceProviderApiRequest $request): JsonResponse
    {
        $this->authorize('create', InsuranceProvider::class);

        $data = $this->clean($request->validated());

        $archived = InsuranceProvider::onlyTrashed()->where('name', $data['name'])->first();
        if ($archived !== null) {
            $archived->restore();
            $archived->update($data);
            $provider = $archived;
        } else {
            $provider = InsuranceProvider::create($data);
        }

        return ApiResponse::success((new InsuranceProviderResource($provider->refresh()))->resolve(), 'Provider created.', 201);
    }

    /** insurance.manage. */
    public function update(InsuranceProviderApiRequest $request, InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('update', $insuranceProvider);

        $insuranceProvider->update($this->clean($request->validated()));

        return ApiResponse::success((new InsuranceProviderResource($insuranceProvider->refresh()))->resolve(), 'Provider updated.');
    }

    /**
     * insurance.view. The insurer's figures (cards, in debt, owed, shortfall), the float proved
     * against its ledger, its member cards (in debt first, up to 200), the latest 5 ledger lines
     * and the usage report for ?from=&to= (default this month).
     */
    public function show(Request $request, InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        $cards = PatientCard::where('insurance_provider_id', $insuranceProvider->id);
        $owed = $insuranceProvider->outstanding();

        $usage = $this->ledger->usage(
            $insuranceProvider,
            Carbon::parse($this->date($request->query('from')) ?? Carbon::now()->startOfMonth()->toDateString()),
            Carbon::parse($this->date($request->query('to')) ?? Carbon::now()->toDateString()),
        );

        return ApiResponse::success((new InsuranceProviderResource($insuranceProvider))->resolve() + [
            'figures' => [
                'cards' => (clone $cards)->count(),
                'in_debt' => (clone $cards)->where('balance', '<', 0)->count(),
                'owed' => $owed,
                // Negative: the float cannot clear what the members owe.
                'shortfall' => bcsub((string) $insuranceProvider->float_balance, $owed, 2),
            ],
            'proof' => $this->ledger->reconcile($insuranceProvider),
            'cards' => PatientCardResource::collection(
                PatientCard::with('patient')->where('insurance_provider_id', $insuranceProvider->id)
                    ->orderByRaw('CASE WHEN balance < 0 THEN 0 ELSE 1 END')->orderBy('balance')->limit(200)->get()
            )->resolve(),
            'recent' => InsuranceTransactionResource::collection(
                InsuranceTransaction::with(['card.patient', 'createdBy'])->where('insurance_provider_id', $insuranceProvider->id)->latest('id')->limit(5)->get()
            )->resolve(),
            'usage' => [
                'from' => $usage['from']->toDateString(),
                'to' => $usage['to']->toDateString(),
                'members' => $usage['members'],
                'spent' => $usage['spent'],
                'refunded' => $usage['refunded'],
                'net' => $usage['net'],
                'deposits' => $usage['deposits'],
                'settlements' => $usage['settlements'],
                'float' => $usage['float'],
                'outstanding' => $usage['outstanding'],
            ],
            'can_manage' => $request->user()->can('insurance.manage'),
        ]);
    }

    /** insurance.view. The float's ledger, newest first, 20 a page. */
    public function ledger(InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('viewAny', InsuranceProvider::class);

        $rows = InsuranceTransaction::with(['card.patient', 'createdBy'])
            ->where('insurance_provider_id', $insuranceProvider->id)
            ->latest('created_at')->latest('id')->paginate(20);

        return ApiResponse::paginated($rows, InsuranceTransactionResource::collection($rows->getCollection())->resolve());
    }

    /** insurance.manage. {amount, reference?, notes?}: money the insurer has placed with the hospital. */
    public function deposit(FloatDepositRequest $request, InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('update', $insuranceProvider);

        $d = $request->validated();

        try {
            $entry = $this->ledger->deposit($insuranceProvider, $this->money($d['amount']), $d['reference'] ?? null, $d['notes'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'amount');
        }

        return ApiResponse::success((new InsuranceTransactionResource($entry))->resolve() + ['float_balance' => (string) $insuranceProvider->refresh()->float_balance], 'Deposit recorded.', 201);
    }

    /** insurance.manage. {direction: add|remove, amount, reason}: correct the float. May not take it below zero. */
    public function adjust(FloatAdjustRequest $request, InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('update', $insuranceProvider);

        $d = $request->validated();
        $amount = $this->money($d['amount']);
        $signed = $d['direction'] === 'remove' ? bcmul($amount, '-1', 2) : $amount;

        try {
            $entry = $this->ledger->adjust($insuranceProvider, $signed, $d['reason'], $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'amount');
        }

        return ApiResponse::success((new InsuranceTransactionResource($entry))->resolve() + ['float_balance' => (string) $insuranceProvider->refresh()->float_balance], 'Float corrected.', 201);
    }

    /** insurance.manage. Clear every member's debt the float can cover. */
    public function settleAll(Request $request, InsuranceProvider $insuranceProvider): JsonResponse
    {
        $this->authorize('update', $insuranceProvider);

        try {
            $result = $this->ledger->settleAll($insuranceProvider, $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e);
        }

        $money = fn (string $a) => HospitalSettings::money($a);
        $message = $result['settled'] === 0
            ? 'Nothing to clear.'
            : (bccomp($result['unpaid'], '0', 2) > 0
                ? "{$result['settled']} cleared, {$money($result['paid'])} paid — {$money($result['unpaid'])} still owing, the float ran out."
                : "{$result['settled']} cleared, {$money($result['paid'])} paid.");

        return ApiResponse::success($result, $message);
    }

    /** insurance.manage. Clear one member card's debt from the float. */
    public function settleCard(Request $request, InsuranceProvider $insuranceProvider, PatientCard $card): JsonResponse
    {
        $this->authorize('update', $insuranceProvider);
        abort_unless($card->insurance_provider_id === $insuranceProvider->id, 404);

        try {
            $entry = $this->ledger->settleCard($card, null, $request->user()->id);
        } catch (RuntimeException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success(
            ['settled' => $entry === null ? '0.00' : (string) $entry->amount, 'float_balance' => (string) $insuranceProvider->refresh()->float_balance],
            $entry === null ? 'Nothing to clear on that card.' : HospitalSettings::money((string) $entry->amount).' cleared.',
        );
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clean(array $data): array
    {
        foreach (['code', 'contact_person', 'contact_phone', 'contact_email'] as $f) {
            if (array_key_exists($f, $data) && is_string($data[$f]) && trim($data[$f]) === '') {
                $data[$f] = null;
            }
        }

        return $data;
    }

    private function money(string|int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
