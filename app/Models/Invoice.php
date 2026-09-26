<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money source of truth for a bill. All monetary columns are decimal(12,2) and
 * only BillingService writes them (inside transactions). amount_paid/balance are
 * kept in step with the append-only payments rows.
 *
 * @property InvoiceStatus $status
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property numeric-string $subtotal
 * @property numeric-string $tax_total
 * @property numeric-string $discount
 * @property numeric-string $total
 * @property numeric-string $amount_paid
 * @property numeric-string $balance
 */
class Invoice extends Model
{
    use BelongsToHospital, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'invoice_no', 'visit_id', 'patient_id', 'currency',
        'subtotal', 'tax_total', 'discount', 'total', 'amount_paid', 'balance',
        'status', 'notes', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'discount' => 'decimal:2',
            'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Invoice number, or the patient's name or number — the ledger's search,
     * shared by the web and the API.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn (Builder $q) => $q
            ->where('invoice_no', 'like', "%{$term}%")
            ->orWhereHas('patient', fn ($p) => Patient::matchWords($p, $term)));
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('created_at')->latest('id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
