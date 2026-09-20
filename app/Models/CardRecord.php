<?php

namespace App\Models;

use App\Enums\CardEntryType;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable line of a card's ledger. Append-only: no updated_at, never
 * edited (constraint F).
 *
 * `patient_id` is WHO THE MONEY WAS SPENT ON, which on a family card is not
 * always the person the card belongs to. It is what a per-member usage report
 * is made of (docs/cards.md).
 *
 * @property CardEntryType $type
 * @property string $amount
 * @property string $balance_after
 * @property-read Patient|null $patient
 * @property-read PatientCard|null $card
 * @property-read InsuranceTransaction|null $settlement
 * @property-read User|null $createdBy
 */
class CardRecord extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'patient_card_id', 'patient_id', 'type', 'amount',
        'balance_after', 'description', 'reference', 'insurance_transaction_id',
        'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CardEntryType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(PatientCard::class, 'patient_card_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The member this was spent on — the cardholder unless it says otherwise. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** The insurer's settlement that paid for this credit, where there was one. */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(InsuranceTransaction::class, 'insurance_transaction_id');
    }

    /** What this line did to the balance, with its sign in front of it. */
    public function signedAmount(): string
    {
        $amount = (string) $this->amount;

        return $this->type === CardEntryType::Debit ? bcmul($amount, '-1', 2) : $amount;
    }
}
