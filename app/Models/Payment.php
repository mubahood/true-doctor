<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only payment against an invoice (no updated_at). A card payment links
 * to the card ledger row it created so the two money trails reconcile.
 *
 * @property PaymentMethod $method
 * @property numeric-string $amount
 * @property numeric-string $balance_after
 */
class Payment extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'invoice_id', 'patient_id', 'method', 'amount', 'balance_after',
        'patient_card_id', 'card_record_id', 'reference', 'received_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The card this was taken off, where it was a card payment.
     *
     * The column has been here since prepaid cards shipped; nothing could
     * follow it, so a receipt could not say which card was debited.
     *
     * @return BelongsTo<PatientCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(PatientCard::class, 'patient_card_id');
    }

    /** @return BelongsTo<CardRecord, $this> */
    public function cardRecord(): BelongsTo
    {
        return $this->belongsTo(CardRecord::class, 'card_record_id');
    }
}
