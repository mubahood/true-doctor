<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery received against a purchase order (a GRN). Append-only.
 *
 * @property numeric-string $total
 * @property \Illuminate\Support\Carbon $received_on
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class GoodsReceipt extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'grn_no', 'purchase_order_id', 'supplier_id', 'received_on', 'supplier_invoice_no',
        'total', 'notes', 'received_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
