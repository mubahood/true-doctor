<?php

namespace App\Services;

use App\Enums\CardEntryType;
use App\Enums\CardHolderRelationship;
use App\Enums\CardHolderStatus;
use App\Enums\CardStatus;
use App\Exceptions\InsufficientFundsException;
use App\Models\CardHolder;
use App\Models\CardRecord;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Everything a card does. All balance movement runs inside a transaction with a
 * locking read on the card row (constraint F — no lost updates on concurrent
 * top-ups and charges), and is recorded as an immutable ledger entry with a
 * balance snapshot. Money math uses bcmath at scale 2 (no float drift).
 *
 * The three things beyond a balance, all of them documented in docs/cards.md:
 * who may spend on a card (`card_holders`), what its terms are, and which
 * insurer stands behind it.
 */
class CardService
{
    /**
     * @param  array{expiry?:string|null,accepts_credit?:bool,max_credit?:mixed,insurance_provider_id?:int|null,member_no?:string|null}  $data
     */
    public function issue(Patient $patient, array $data, ?int $issuedBy = null): PatientCard
    {
        $provider = $this->resolveProvider($data['insurance_provider_id'] ?? null);

        return DB::transaction(function () use ($patient, $data, $issuedBy, $provider) {
            [$number, $hash] = $this->allocateNumber();

            // An insurance card exists to run negative, so it starts with the
            // insurer's own terms rather than with a clerk remembering them.
            $acceptsCredit = (bool) ($data['accepts_credit'] ?? false);
            $maxCredit = $this->decimal($data['max_credit'] ?? 0);

            if ($provider !== null && ! $acceptsCredit && bccomp((string) $provider->default_credit_limit, '0', 2) > 0) {
                $acceptsCredit = true;
                $maxCredit = (string) $provider->default_credit_limit;
            }

            return PatientCard::create([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $patient->id,
                'insurance_provider_id' => $provider?->id,
                'member_no' => $provider === null ? null : (trim((string) ($data['member_no'] ?? '')) ?: null),
                'card_number' => $number,
                'card_hash' => $hash,
                'expiry' => $data['expiry'] ?? null,
                'status' => CardStatus::Active,
                'accepts_credit' => $acceptsCredit,
                'max_credit' => $acceptsCredit ? $maxCredit : '0.00',
                'balance' => 0,
                'issued_by' => $issuedBy,
            ]);
        });
    }

    // ── Terms ────────────────────────────────────────────────────────────

    /**
     * Change what a card is allowed to do, after it has been issued.
     *
     * Until now terms could only be set when the card was made, so the only way
     * to raise a family's limit or stop a lost card was to issue another one.
     *
     * @param  array{status?:string,accepts_credit?:bool,max_credit?:mixed,expiry?:string|null,member_no?:string|null,insurance_provider_id?:int|null}  $data
     */
    public function updateTerms(PatientCard $card, array $data, ?int $by = null): PatientCard
    {
        $provider = array_key_exists('insurance_provider_id', $data)
            ? $this->resolveProvider($data['insurance_provider_id'])
            : false;   // absent means "leave it alone", which null does not

        return DB::transaction(function () use ($card, $data, $provider) {
            /** @var PatientCard $locked */
            $locked = PatientCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            $acceptsCredit = array_key_exists('accepts_credit', $data)
                ? (bool) $data['accepts_credit']
                : $locked->accepts_credit;

            $maxCredit = $acceptsCredit
                ? $this->decimal($data['max_credit'] ?? $locked->max_credit)
                : '0.00';

            // A limit cannot be pulled below a debt already taken against it:
            // the card would be over a limit it never agreed to, and nothing in
            // the system could bring it back but a top-up.
            $debt = $locked->debt();
            if (bccomp($debt, '0', 2) > 0 && bccomp($maxCredit, $debt, 2) < 0) {
                throw new RuntimeException(
                    'This card already owes '.\App\Support\HospitalSettings::money($debt).
                    ' — clear it before lowering the limit below that.'
                );
            }

            $status = isset($data['status'])
                ? CardStatus::from($data['status'])
                : $locked->status;

            $locked->fill([
                'status' => $status,
                'accepts_credit' => $acceptsCredit,
                'max_credit' => $maxCredit,
            ]);

            if (array_key_exists('expiry', $data)) {
                $locked->expiry = $data['expiry'] ? Carbon::parse($data['expiry']) : null;
            }

            if ($provider !== false) {
                $locked->insurance_provider_id = $provider?->id;
                $locked->member_no = $provider === null ? null : $locked->member_no;
            }

            if (array_key_exists('member_no', $data)) {
                $locked->member_no = $locked->insurance_provider_id === null
                    ? null
                    : (trim((string) $data['member_no']) ?: null);
            }

            $locked->save();

            return $locked->refresh();
        });
    }

    // ── Holders ──────────────────────────────────────────────────────────

    /**
     * Let somebody else spend on this card.
     *
     * Re-adding a revoked holder flips their row back rather than stacking a
     * second one beside it, so the card never shows the same person twice.
     */
    public function addHolder(PatientCard $card, Patient $patient, CardHolderRelationship $relationship, ?int $by = null): CardHolder
    {
        if ($patient->hospital_id !== $card->hospital_id) {
            throw new RuntimeException('That patient belongs to another hospital.');
        }

        if ($card->patient_id === $patient->id) {
            throw new RuntimeException('This card was issued to them — they already hold it.');
        }

        return DB::transaction(function () use ($card, $patient, $relationship, $by) {
            /** @var CardHolder|null $existing */
            $existing = CardHolder::where('patient_card_id', $card->id)
                ->where('patient_id', $patient->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->fill([
                    'relationship' => $relationship,
                    'status' => CardHolderStatus::Active,
                    'added_by' => $by,
                    'revoked_by' => null,
                    'revoked_at' => null,
                ])->save();

                return $existing->refresh();
            }

            return CardHolder::create([
                'patient_card_id' => $card->id,
                'patient_id' => $patient->id,
                'relationship' => $relationship,
                'status' => CardHolderStatus::Active,
                'added_by' => $by,
            ]);
        });
    }

    /** Take somebody off a card. The row stays; what they spent is still theirs. */
    public function revokeHolder(CardHolder $holder, ?int $by = null): CardHolder
    {
        if ($holder->status === CardHolderStatus::Revoked) {
            return $holder;
        }

        $holder->fill([
            'status' => CardHolderStatus::Revoked,
            'revoked_by' => $by,
            'revoked_at' => Carbon::now(),
        ])->save();

        return $holder->refresh();
    }

    // ── Money ────────────────────────────────────────────────────────────

    public function credit(PatientCard $card, string $amount, ?string $description, ?int $by = null, array $opts = []): CardRecord
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());
        $this->assertPositive($amount);

        return $this->post($card, CardEntryType::Credit, $amount, $description, $by, $opts);
    }

    public function debit(PatientCard $card, string $amount, ?string $description, ?int $by = null, array $opts = []): CardRecord
    {
        app(FinancialYearService::class)->assertPostingAllowed(Carbon::now());
        $this->assertPositive($amount);

        return $this->post($card, CardEntryType::Debit, $amount, $description, $by, $opts);
    }

    /**
     * @param  array{for?:Patient|int|null,reference?:string|null,insurance_transaction_id?:int|null}  $opts
     */
    private function post(PatientCard $card, CardEntryType $type, string $amount, ?string $description, ?int $by, array $opts = []): CardRecord
    {
        return DB::transaction(function () use ($card, $type, $amount, $description, $by, $opts) {
            /** @var PatientCard $locked */
            $locked = PatientCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransact()) {
                throw new RuntimeException("Card is {$locked->status->value}; no transactions allowed.");
            }
            if ($locked->isExpired()) {
                throw new RuntimeException('Card has expired.');
            }

            // Who it was spent on. Defaults to the person the card belongs to,
            // and anybody else must actually be on the card — a family card is
            // a shared purse, not an open one.
            $for = $opts['for'] ?? null;
            $forId = $for instanceof Patient ? $for->id : $for;
            $forId ??= $locked->patient_id;

            if ($forId !== $locked->patient_id && ! $locked->covers($forId)) {
                throw new RuntimeException('That patient is not on this card.');
            }

            $balance = (string) $locked->balance;
            $newBalance = $type === CardEntryType::Credit
                ? bcadd($balance, $amount, 2)
                : bcsub($balance, $amount, 2);

            if ($type === CardEntryType::Debit && bccomp($newBalance, '0', 2) < 0) {
                $overdraft = bcmul($newBalance, '-1', 2);
                if (! $locked->accepts_credit || bccomp($overdraft, (string) $locked->max_credit, 2) > 0) {
                    throw InsufficientFundsException::forCard($amount, $balance);
                }
            }

            $locked->update(['balance' => $newBalance]);

            return CardRecord::create([
                'uuid' => (string) Str::uuid(),
                'patient_card_id' => $locked->id,
                'patient_id' => $forId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference' => $opts['reference'] ?? null,
                'insurance_transaction_id' => $opts['insurance_transaction_id'] ?? null,
                'created_by' => $by,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Prove the balance against its own ledger.
     *
     * The balance is a cache; `card_records` is the truth. Anything that ever
     * wrote one without the other would show up here.
     *
     * @return array{balance:string,ledger:string,agrees:bool}
     */
    public function reconcile(PatientCard $card): array
    {
        $ledger = '0.00';

        foreach (CardRecord::where('patient_card_id', $card->id)->orderBy('id')->get(['type', 'amount']) as $record) {
            $ledger = bcadd($ledger, $record->signedAmount(), 2);
        }

        $balance = (string) $card->balance;

        return ['balance' => $balance, 'ledger' => $ledger, 'agrees' => bccomp($balance, $ledger, 2) === 0];
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    private function resolveProvider(mixed $id): ?InsuranceProvider
    {
        if ($id === null || $id === '' || $id === 0) {
            return null;
        }

        // Tenant-scoped by the global scope, so another hospital's insurer is
        // simply not there to be found.
        $provider = InsuranceProvider::find((int) $id);

        if ($provider === null) {
            throw new RuntimeException('That insurer is not one of this hospital’s.');
        }

        return $provider;
    }

    /** @return array{0:string,1:string} [number, hash] unique per hospital. */
    private function allocateNumber(): array
    {
        for ($i = 0; $i < 5; $i++) {
            $number = (string) random_int(1000_0000_0000_0000, 9999_9999_9999_9999);
            $hash = hash_hmac('sha256', $number, (string) (config('app.card_hash_key') ?: config('app.key')));
            if (! PatientCard::where('card_hash', $hash)->exists()) {
                return [$number, $hash];
            }
        }

        throw new RuntimeException('Could not allocate a unique card number.');
    }

    private function decimal(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function assertPositive(string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
    }
}
