<?php

namespace App\Models;

use App\Enums\CardHolderStatus;
use App\Enums\CardStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A card. Balance only ever changes through CardService inside a transaction;
 * the card_records ledger is the source of truth (constraint F). card_number is
 * encrypted; card_hash is the lookup key.
 *
 * Two things about a card are not obvious from its columns (docs/cards.md):
 *
 *  - `patient_id` is the PRIMARY HOLDER, not the only one. A family shares one
 *    card through `card_holders`, and `covers()` is the single question
 *    everything asks before letting somebody spend on it.
 *  - `insurance_provider_id` makes it an INSURANCE card: it is meant to run
 *    negative, and the company behind it clears the debt against a float.
 *
 * @property string $card_number
 * @property string $card_hash
 * @property CardStatus $status
 * @property string $balance
 * @property string $max_credit
 * @property bool $accepts_credit
 * @property \Illuminate\Support\Carbon|null $expiry
 * @property-read \Illuminate\Database\Eloquent\Collection<int,CardHolder> $holders
 */
class PatientCard extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'patient_id', 'insurance_provider_id', 'member_no',
        'card_number', 'card_hash', 'expiry',
        'status', 'accepts_credit', 'max_credit', 'balance', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'card_number' => 'encrypted',
            'expiry' => 'date',
            'status' => CardStatus::class,
            'accepts_credit' => 'boolean',
            'max_credit' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    protected $hidden = ['card_number', 'card_hash'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(CardRecord::class)->latest('created_at')->latest('id');
    }

    /** Everyone besides the primary holder, revoked ones included. */
    public function holders(): HasMany
    {
        return $this->hasMany(CardHolder::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Every card this patient may spend on: their own, and any a family member
     * has put them on. The counter has to be offered all of them or the family
     * card is invisible at the one moment it matters.
     *
     * @param  Builder<PatientCard>  $query
     */
    public function scopeSpendableBy(Builder $query, int $patientId): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('patient_id', $patientId)
            ->orWhereHas('holders', fn (Builder $h) => $h
                ->where('patient_id', $patientId)
                ->where('status', CardHolderStatus::Active)));
    }

    /** Masked number for display, e.g. •••• 4821. */
    public function masked(): string
    {
        $n = preg_replace('/\D/', '', (string) $this->card_number);

        return '•••• '.substr($n, -4);
    }

    public function isExpired(): bool
    {
        return $this->expiry !== null && $this->expiry->isPast();
    }

    /** An insurer stands behind this card, so its debt is theirs to clear. */
    public function isInsurance(): bool
    {
        return $this->insurance_provider_id !== null;
    }

    /**
     * May this patient spend on this card?
     *
     * The primary holder always may. Anybody else needs an active holder row.
     * This is the ONLY place the question is answered — BillingService used to
     * compare `patient_id` by hand, which is what made a family card useless.
     */
    public function covers(Patient|int $patient): bool
    {
        $id = $patient instanceof Patient ? $patient->id : $patient;

        if ($this->patient_id === $id) {
            return true;
        }

        return $this->holders()
            ->where('patient_id', $id)
            ->where('status', CardHolderStatus::Active)
            ->exists();
    }

    /** What is owed on it: a positive figure, or zero when it is in funds. */
    public function debt(): string
    {
        $balance = (string) $this->balance;

        return bccomp($balance, '0', 2) < 0 ? bcmul($balance, '-1', 2) : '0.00';
    }

    /** What is still spendable: the balance plus whatever credit is left. */
    public function spendable(): string
    {
        $balance = (string) $this->balance;

        return $this->accepts_credit
            ? bcadd($balance, (string) $this->max_credit, 2)
            : $balance;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
