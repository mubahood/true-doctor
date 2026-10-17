<?php

namespace App\Models;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A credit note or a refund on an invoice (docs/refunds.md).
 *
 * Append-only money: only InvoiceCreditService writes it. A row is asked for
 * (pending) and decided once; after that it never changes and is never
 * deleted. A mistake in an approved one is put right by another document,
 * the way a payment is.
 *
 * @property CreditKind $kind
 * @property CreditStatus $status
 * @property PaymentMethod|null $method
 * @property numeric-string $amount
 * @property numeric-string $tax_part
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon|null $decided_at
 */
class InvoiceCredit extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'hospital_id', 'invoice_id', 'patient_id', 'kind', 'number', 'status',
        'amount', 'tax_part', 'method', 'reference', 'patient_card_id', 'card_record_id',
        'reason', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CreditKind::class,
            'status' => CreditStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'tax_part' => 'decimal:2',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Decided is final. The one write after the request is the decision.
        static::updating(function (self $credit) {
            if ($credit->getRawOriginal('status') !== CreditStatus::Pending->value) {
                throw new LogicException('A decided credit note or refund cannot be changed.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Credit notes and refunds are never deleted.');
        });
    }

    /** The number once approved; until then, what it is waiting for. */
    public function label(): string
    {
        return $this->number ?? match ($this->status) {
            CreditStatus::Rejected => 'Rejected',
            default => 'Pending',
        };
    }

    public function isPending(): bool
    {
        return $this->status === CreditStatus::Pending;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<PatientCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(PatientCard::class, 'patient_card_id');
    }

    /**
     * Its number, its invoice's number, or the patient — shared by the web
     * list and the API.
     *
     * @param  Builder<InvoiceCredit>  $query
     * @return Builder<InvoiceCredit>
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn (Builder $q) => $q
            ->where('number', 'like', "%{$term}%")
            ->orWhereIn('invoice_id', Invoice::query()->matching($term)->select('id')));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
