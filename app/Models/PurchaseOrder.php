<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * An order to a supplier. Its status, lines and totals change only through
 * App\Services\ProcurementService.
 *
 * @property PurchaseOrderStatus $status
 * @property numeric-string $total
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property \Illuminate\Support\Carbon|null $ordered_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
class PurchaseOrder extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'uuid', 'po_no', 'supplier_id', 'status', 'order_date', 'expected_date', 'notes', 'total',
        'created_by', 'ordered_at', 'ordered_by', 'ended_at', 'ended_by', 'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'ordered_at' => 'datetime',
            'ended_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    /**
     * What went back to the supplier from this order's deliveries.
     *
     * @return HasManyThrough<SupplierReturn, GoodsReceipt, $this>
     */
    public function returns(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierReturn::class, GoodsReceipt::class)->orderBy('supplier_returns.id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * The list's filters, shared by the web list and the API. `open` means
     * ordered and still waiting for goods.
     *
     * @param  Builder<PurchaseOrder>  $q
     * @return Builder<PurchaseOrder>
     */
    public function scopeListed(Builder $q, ?string $status, int|string|null $supplier, ?string $search): Builder
    {
        $search = trim((string) $search);
        $status = (string) $status;

        return $q
            ->when($status === 'open', fn (Builder $x) => $x->whereIn('status', [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::PartReceived->value]))
            ->when($status !== '' && $status !== 'open', fn (Builder $x) => $x->where('status', $status))
            ->when(($supplier ?? '') !== '' && (int) $supplier > 0, fn (Builder $x) => $x->where('supplier_id', (int) $supplier))
            ->when($search !== '', fn (Builder $x) => $x->where(fn (Builder $y) => $y
                ->where('po_no', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$search}%"))));
    }

    /**
     * Still waiting for goods past the day they were expected: the list's red
     * date on the web and `is_overdue` in the API.
     */
    public function isOverdue(): bool
    {
        return $this->expected_date !== null
            && $this->expected_date->isPast()
            && $this->status->canReceive();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
