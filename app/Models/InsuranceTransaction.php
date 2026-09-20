<?php

namespace App\Models;

use App\Enums\InsuranceEntryType;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable line of an insurer's float ledger. Append-only: no updated_at,
 * never edited, corrected only by an adjustment beside it (docs/cards.md).
 *
 * @property InsuranceEntryType $type
 * @property string $amount
 * @property string $balance_after
 */
class InsuranceTransaction extends Model
{
    use BelongsToHospital, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'insurance_provider_id', 'type', 'amount', 'balance_after',
        'patient_card_id', 'reference', 'notes', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InsuranceEntryType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InsuranceProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }

    /** @return BelongsTo<PatientCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(PatientCard::class, 'patient_card_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What this line did to the float, with its sign in front of it. */
    public function signedAmount(): string
    {
        $amount = (string) $this->amount;

        return $this->type === InsuranceEntryType::Settlement
            ? bcmul($amount, '-1', 2)
            : $amount;
    }
}
