<?php

namespace App\Services;

use App\Enums\CardEntryType;
use App\Enums\InsuranceEntryType;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\InsuranceTransaction;
use App\Models\PatientCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The insurer's side of a card (docs/cards.md).
 *
 * An insurer deposits money with the hospital; that money clears its members'
 * negative cards. Two append-only ledgers are involved and neither may move
 * without the other, so a settlement writes both rows in ONE transaction and
 * links them to each other — a settlement that cannot write both writes
 * neither.
 *
 * Locking order is fixed everywhere in this class: the PROVIDER first, then the
 * card. Two clerks settling the same insurer at once therefore queue rather
 * than deadlock, and neither can spend the same float twice.
 */
class InsuranceLedgerService
{
    public function __construct(private readonly CardService $cards) {}

    /** Money placed with the hospital, against which member cards are cleared. */
    public function deposit(InsuranceProvider $provider, string $amount, ?string $reference, ?string $notes, ?int $by = null): InsuranceTransaction
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());
        $this->assertPositive($amount);

        return DB::transaction(function () use ($provider, $amount, $reference, $notes, $by) {
            $locked = $this->lock($provider);

            return $this->write(
                $locked,
                InsuranceEntryType::Deposit,
                $amount,
                bcadd((string) $locked->float_balance, $amount, 2),
                null,
                $reference,
                $notes,
                $by,
            );
        });
    }

    /**
     * Correct a mistake. A ledger row is never edited or deleted, so the way
     * back is another row saying what was wrong with the last one.
     *
     * The amount carries its own sign: a negative adjustment takes the float
     * down. It may not take it below zero — the hospital cannot hold less than
     * nothing of somebody else's money.
     */
    public function adjust(InsuranceProvider $provider, string $amount, string $reason, ?int $by = null): InsuranceTransaction
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        if (bccomp($amount, '0', 2) === 0) {
            throw new RuntimeException('An adjustment of nothing changes nothing.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the float is being adjusted.');
        }

        return DB::transaction(function () use ($provider, $amount, $reason, $by) {
            $locked = $this->lock($provider);
            $after = bcadd((string) $locked->float_balance, $amount, 2);

            if (bccomp($after, '0', 2) < 0) {
                throw new RuntimeException(
                    'That would take the float below zero — it holds '.
                    \App\Support\HospitalSettings::money((string) $locked->float_balance).'.'
                );
            }

            return $this->write($locked, InsuranceEntryType::Adjustment, $amount, $after, null, null, $reason, $by);
        });
    }

    /**
     * Clear what one card owes, out of the insurer's float.
     *
     * Pays the smaller of the debt and what is left of the float, so a float
     * that runs out clears what it can and stops rather than failing outright.
     * Returns null when there was nothing to pay — which is a result, not an
     * error: settling an insurer whose members are all square is a no-op, and
     * pressing the button twice must not post twice.
     */
    public function settleCard(PatientCard $card, ?string $amount = null, ?int $by = null): ?InsuranceTransaction
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        if (! $card->isInsurance()) {
            throw new RuntimeException('This card has no insurer behind it.');
        }

        /** @var InsuranceProvider $provider */
        $provider = InsuranceProvider::findOrFail($card->insurance_provider_id);

        return DB::transaction(function () use ($card, $provider, $amount, $by) {
            $lockedProvider = $this->lock($provider);

            /** @var PatientCard $lockedCard */
            $lockedCard = PatientCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            $pay = $this->amountToSettle($lockedCard, $lockedProvider, $amount);

            if ($pay === null) {
                return null;
            }

            return $this->post($lockedProvider, $lockedCard, $pay, $by);
        });
    }

    /**
     * Clear every member card this insurer stands behind, oldest debt first.
     *
     * One transaction and one lock on the provider for the whole sweep: a
     * second clerk pressing the same button waits, finds nothing left owing,
     * and posts nothing.
     *
     * @return array{settled:int,paid:string,unpaid:string,float:string}
     */
    public function settleAll(InsuranceProvider $provider, ?int $by = null): array
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        return DB::transaction(function () use ($provider, $by) {
            $lockedProvider = $this->lock($provider);

            $cards = PatientCard::where('insurance_provider_id', $lockedProvider->id)
                ->where('balance', '<', 0)
                ->orderBy('updated_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $settled = 0;
            $paid = '0.00';
            $unpaid = '0.00';

            foreach ($cards as $card) {
                $pay = $this->amountToSettle($card, $lockedProvider, null);

                if ($pay === null) {
                    $unpaid = bcadd($unpaid, $card->debt(), 2);

                    continue;
                }

                $this->post($lockedProvider, $card, $pay, $by);
                $settled++;
                $paid = bcadd($paid, $pay, 2);
                $unpaid = bcadd($unpaid, bcsub($card->debt(), $pay, 2), 2);

                $lockedProvider->refresh();
            }

            return [
                'settled' => $settled,
                'paid' => $paid,
                'unpaid' => $unpaid,
                'float' => (string) $lockedProvider->refresh()->float_balance,
            ];
        });
    }

    // ── The usage report ─────────────────────────────────────────────────

    /**
     * What the hospital hands the insurer: who spent what, between two dates.
     *
     * Usage is read off `card_records`, whose `patient_id` is the MEMBER the
     * money was spent on — on a family card that is not always the cardholder.
     *
     * @return array{
     *     provider:InsuranceProvider,
     *     from:Carbon, to:Carbon,
     *     members:list<array{patient:string,patient_no:string,member_no:?string,card:string,entries:int,spent:string}>,
     *     spent:string, refunded:string, net:string,
     *     deposits:string, settlements:string,
     *     float:string, outstanding:string
     * }
     */
    public function usage(InsuranceProvider $provider, Carbon $from, Carbon $to): array
    {
        $window = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $cardIds = PatientCard::where('insurance_provider_id', $provider->id)->pluck('member_no', 'id');

        $records = CardRecord::whereIn('patient_card_id', $cardIds->keys())
            ->whereBetween('created_at', $window)
            // A settlement's credit is the insurer paying its own bill, not the
            // member receiving anything — it would otherwise cancel out the
            // very usage this report exists to show.
            ->whereNull('insurance_transaction_id')
            ->with(['patient', 'card'])
            ->orderBy('created_at')
            ->get();

        /** @var array<string,array{patient:string,patient_no:string,member_no:?string,card:string,entries:int,spent:string}> $members */
        $members = [];
        $spent = '0.00';
        $refunded = '0.00';

        foreach ($records as $record) {
            $isSpend = $record->type === CardEntryType::Debit;
            $isSpend
                ? $spent = bcadd($spent, (string) $record->amount, 2)
                : $refunded = bcadd($refunded, (string) $record->amount, 2);

            $key = $record->patient_id.':'.$record->patient_card_id;

            $members[$key] ??= [
                'patient' => $record->patient?->fullName() ?? 'Unknown',
                'patient_no' => (string) ($record->patient->patient_no ?? '—'),
                'member_no' => $cardIds[$record->patient_card_id] ?? null,
                'card' => $record->card?->masked() ?? '—',
                'entries' => 0,
                'spent' => '0.00',
            ];

            $members[$key]['entries']++;
            // Positive means "this member cost the insurer this much", which is
            // the same direction as the report's own total. A refund pulls it
            // down, and a member refunded more than they spent reads negative —
            // which is exactly what happened.
            $members[$key]['spent'] = bcsub($members[$key]['spent'], $record->signedAmount(), 2);
        }

        // Biggest spender first: the first question anybody asks of this page.
        $rows = array_values($members);
        usort($rows, fn (array $a, array $b) => bccomp($b['spent'], $a['spent'], 2));

        $ledger = InsuranceTransaction::where('insurance_provider_id', $provider->id)
            ->whereBetween('created_at', $window)
            ->get(['type', 'amount']);

        return [
            'provider' => $provider,
            'from' => $from->copy()->startOfDay(),
            'to' => $to->copy()->endOfDay(),
            'members' => $rows,
            'spent' => $spent,
            'refunded' => $refunded,
            'net' => bcsub($spent, $refunded, 2),
            'deposits' => $this->sumOf($ledger, InsuranceEntryType::Deposit),
            'settlements' => $this->sumOf($ledger, InsuranceEntryType::Settlement),
            'float' => (string) $provider->float_balance,
            'outstanding' => $provider->outstanding(),
        ];
    }

    /**
     * Prove the float against its own ledger, exactly as a card is proved
     * against `card_records`.
     *
     * @return array{float:string,ledger:string,agrees:bool}
     */
    public function reconcile(InsuranceProvider $provider): array
    {
        $ledger = '0.00';

        foreach (InsuranceTransaction::where('insurance_provider_id', $provider->id)->orderBy('id')->get(['type', 'amount']) as $entry) {
            $ledger = bcadd($ledger, $entry->signedAmount(), 2);
        }

        $float = (string) $provider->float_balance;

        return ['float' => $float, 'ledger' => $ledger, 'agrees' => bccomp($float, $ledger, 2) === 0];
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /**
     * How much of this card's debt the float can clear right now, or null when
     * the answer is "none" — a settled card, or an exhausted float.
     */
    private function amountToSettle(PatientCard $card, InsuranceProvider $provider, ?string $asked): ?string
    {
        $debt = $card->debt();
        $float = (string) $provider->float_balance;

        if (bccomp($debt, '0', 2) <= 0 || bccomp($float, '0', 2) <= 0) {
            return null;
        }

        $pay = $debt;

        if ($asked !== null) {
            $this->assertPositive($asked);

            if (bccomp($asked, $debt, 2) > 0) {
                throw new RuntimeException(
                    'That card only owes '.\App\Support\HospitalSettings::money($debt).'.'
                );
            }

            $pay = $asked;
        }

        // A float that runs out pays what it can.
        return bccomp($pay, $float, 2) > 0 ? $float : $pay;
    }

    /** The two rows, written together, pointing at each other. */
    private function post(InsuranceProvider $provider, PatientCard $card, string $amount, ?int $by): InsuranceTransaction
    {
        $entry = $this->write(
            $provider,
            InsuranceEntryType::Settlement,
            $amount,
            bcsub((string) $provider->float_balance, $amount, 2),
            $card->id,
            null,
            'Cleared '.$card->masked(),
            $by,
        );

        $this->cards->credit(
            $card,
            $amount,
            $provider->name.' settlement',
            $by,
            ['insurance_transaction_id' => $entry->id, 'reference' => 'INS-'.$entry->id],
        );

        return $entry;
    }

    private function write(
        InsuranceProvider $provider,
        InsuranceEntryType $type,
        string $amount,
        string $after,
        ?int $cardId,
        ?string $reference,
        ?string $notes,
        ?int $by,
    ): InsuranceTransaction {
        $provider->forceFill(['float_balance' => $after])->save();

        return InsuranceTransaction::create([
            'uuid' => (string) Str::uuid(),
            'insurance_provider_id' => $provider->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $after,
            'patient_card_id' => $cardId,
            'reference' => $reference,
            'notes' => $notes,
            'created_by' => $by,
            'created_at' => now(),
        ]);
    }

    private function lock(InsuranceProvider $provider): InsuranceProvider
    {
        /** @var InsuranceProvider $locked */
        $locked = InsuranceProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();

        if (! $locked->is_active) {
            throw new RuntimeException('This insurer is not active.');
        }

        return $locked;
    }

    /** @param Collection<int,InsuranceTransaction> $entries */
    private function sumOf(Collection $entries, InsuranceEntryType $type): string
    {
        $total = '0.00';

        foreach ($entries->where('type', $type) as $entry) {
            $total = bcadd($total, (string) $entry->amount, 2);
        }

        return $total;
    }

    private function assertPositive(string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
    }
}
