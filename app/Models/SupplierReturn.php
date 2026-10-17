<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use App\Models\Contracts\NamesALedgerParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock sent back to the supplier from a delivery: damaged, wrong item,
 * short-dated. The source of the stock movement that took it off the shelf.
 * Append-only.
 *
 * @property numeric-string $quantity
 * @property numeric-string $unit_cost
 * @property numeric-string $total
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class SupplierReturn extends Model implements NamesALedgerParty
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid', 'return_no', 'supplier_id', 'goods_receipt_id', 'goods_receipt_item_id', 'stock_item_id',
        'quantity', 'unit_cost', 'total', 'reason', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<GoodsReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ledgerParty(): ?string
    {
        return $this->supplier?->name;
    }

    public function ledgerReference(): ?string
    {
        return $this->return_no;
    }
}
